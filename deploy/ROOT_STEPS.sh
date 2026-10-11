#!/usr/bin/env bash
# MaluMail — what root (the owner) does to install it beside the kernel, in order. Written in Phase 0 (2026-10-11); later phases ADD steps
# and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database, php -S and fixtures (tests/setup_dev.sh makes a scratch
# sealing pair in /tmp), and never touch an installed application, Apache, systemd, /etc/malumail or MaluMail.
#
#   bash deploy/ROOT_STEPS.sh                                     prints this plan (the default; nothing changes)
#   sudo bash deploy/ROOT_STEPS.sh apply [--customer-key-file F]  runs every step with no decision in it (0–5), each idempotent
#
# --customer-key-file F   the MaluMail customer key (the Mailbox MCP's) is read from F — its first non-empty line, a bare key or KEY=value —
#                         and written to /etc/malumail/mailer.env alone. Without it, apply asks for it on the terminal (input hidden) when
#                         the file has none yet; with no terminal it leaves a note. The key is never printed, logged or put in config/.env.
# --rotate                refused: a new sealing pair orphans every credential sealed under the old one (below).
# Steps 6 and 7 (the installer's apply and the owner's) are printed and never run.
set -euo pipefail
APP=/srv/apps/malumail
KERNEL=/var/www
MODE=plan; KEYFILE=''
while [ $# -gt 0 ]; do
  case "$1" in
    apply) MODE=apply ;;
    --customer-key-file) KEYFILE=${2:-}; shift ;;
    --rotate)
      echo "REFUSED: --rotate. The sealing pair is made once. A new pair cannot open a single credential sealed under the old one" >&2
      echo "         (every agent's and shared mailbox's password, design §6.1–6.2). Rotation means the mail service opening each credential" >&2
      echo "         with the old secret and sealing it under the new public key in one pass, then retiring the old secret — a later" >&2
      echo "         spec, not built. Nothing was changed." >&2
      exit 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done
if [ "$MODE" = apply ] && [ "$(id -u)" != 0 ]; then echo "apply needs root: sudo bash deploy/ROOT_STEPS.sh apply" >&2; exit 2; fi
ENVF=$APP/config/.env
MAILER_DIR=/etc/malumail
MAILER_ENV=$MAILER_DIR/mailer.env
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }
valof() { [ -r "$1" ] && sed -n "s/^$2=//p" "$1" | tail -1 | sed -E "s/^([\"'])(.*)\\1\$/\\2/" || true; }
# One key into an env file in place (the inode, owner and mode kept). Secrets are never printed: the line says the key only.
keyset() {
    local f=$1 k=$2 v=$3 secret=${4:-} tmp
    if [ "$(valof "$f" "$k")" = "$v" ]; then echo "   $k already set in $f"; return; fi
    if [ "$secret" = secret ]; then echo "+ $k=(not shown) → $f"; else echo "+ $k=$v → $f"; fi
    [ "$MODE" = apply ] || return 0
    tmp=$(mktemp "$f.tmp.XXXXXX"); chmod 600 "$tmp"
    grep -v "^$k=" "$f" > "$tmp" || true; printf '%s=%s\n' "$k" "$v" >> "$tmp"; cat "$tmp" > "$f"; rm -f "$tmp"
}
refuse() { printf 'REFUSED: %s\n' "$1" >&2; shift; [ $# -eq 0 ] || printf '         %s\n' "$@" >&2; if [ "$MODE" = apply ]; then exit 3; fi; echo "   (plan: apply would stop here)"; }
keyadd() { if [ -z "$(valof "$ENVF" "$1")" ]; then keyset "$ENVF" "$1" "$2"; else echo "   $1 kept as it is"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. APACHE'S MODULES (idempotent; Sales CRM's step 0b, 2026-10-09). The vhost rewrites (rewrite), proxies the two MCP servers (proxy,
#    proxy_http) and sets nosniff, the frame option and the referrer policy (headers — this host had it disabled; the vhost guards its
#    Header lines with <IfModule> so a missing module never fails the config test, but the headers are wanted).
echo "== 0. Apache's modules: rewrite, proxy, proxy_http, headers"
run a2enmod -q rewrite proxy proxy_http headers

# ------------------------------------------------------------------------------------------------------------------
# 1. PIN THE FIVE PORTS AND MALUMAIL'S HOSTS before the installer's first apply (Help Desk's lesson of 2026-10-02; design §14, D16). The
#    installer assigns a port only to APP_INTERNAL_PORT and the endpoints' port_env — never to the mail service's (ML_MAILER_PORT) or the
#    image door's (ML_IMAGES_PORT), which its unit templates need. A config/.env holding ONLY these: the installer reads them, fills every
#    other required key itself, and sets fresh role passwords because DB_PASSWORD is absent. On a config/.env that exists, a missing key
#    is added and a present one kept.
echo "== 1. Pin the ports and MaluMail's hosts in $ENVF"
if [ ! -f "$ENVF" ]; then
  run bash -c "install -d -m 755 '$APP/config' && install -m 640 -g www-data /dev/null '$ENVF'"
fi
keyadd APP_INTERNAL_PORT 8199
keyadd MCP_RECORDS_PORT 8852
keyadd MCP_ACTIVITY_PORT 8853
keyadd ML_MAILER_PORT 8854
keyadd ML_IMAGES_PORT 8855
keyadd MALUMAIL_MCP_URL https://api.malumail.com/mcp
keyadd ML_IMAP_HOST mbox01.malumail.com
keyadd ML_IMAP_PORT 993
keyadd ML_SMTP_HOST mbox01.malumail.com
keyadd ML_SMTP_PORT 587
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost|services)'   -> no {{...}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 2. THE TWO SYSTEM USERS (idempotent; design §6.1, §6.4). malumail-mailer runs the mail service — the only holder of open mailbox
#    credentials and the customer key; malumail-images runs the image door — no credentials at all. Each has its own group; neither can log
#    in; neither is in www-data (www-data's group reads every application's config/.env), and www-data is in neither group.
echo "== 2. System users malumail-mailer and malumail-images (nologin, own groups)"
for u in malumail-mailer malumail-images; do
  if ! id -u "$u" >/dev/null 2>&1; then
    run useradd --system --user-group --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin "$u"
  else
    echo "   user $u exists"
  fi
done

# ------------------------------------------------------------------------------------------------------------------
# 3. THE SEALING PAIR AND THE DOOR'S KEY (idempotent; design §6.1–6.2). libsodium crypto_box_seal: PHP holds the PUBLIC key
#    (ML_SEAL_PUBLIC_KEY in config/.env) and seals a mailbox password the moment MaluMail returns it; only the mail service holds the SECRET
#    key (ML_SEAL_SECRET_KEY in /etc/malumail/mailer.env, 0400, owned by malumail-mailer) and opens it. ML_MAILER_KEY signs every call to the
#    mail service's loopback door; it is written to both files.
#    THE PAIR IS MADE ONCE. A new pair would orphan every sealed credential, so this step never replaces one: a public key without its secret,
#    or a secret that does not match the public key, STOPS here. /etc/malumail/mailer.env belongs in the host's backup — losing it means
#    re-issuing every agent's and shared mailbox's password at MaluMail.
echo "== 3. The sealing pair and ML_MAILER_KEY ($MAILER_ENV, $ENVF)"
run install -d -o root -g malumail-mailer -m 0750 "$MAILER_DIR"
if [ "$MODE" = apply ] && [ ! -f "$MAILER_ENV" ]; then install -o malumail-mailer -g malumail-mailer -m 0400 /dev/null "$MAILER_ENV"; fi
if [ "$MODE" != apply ] && [ -e "$MAILER_DIR" ] && { [ ! -x "$MAILER_DIR" ] || { [ -e "$MAILER_ENV" ] && [ ! -r "$MAILER_ENV" ]; }; }; then
  echo "   $MAILER_ENV is not readable by $(id -un) — what apply would do here is decided as root"
else
  PUB=$(valof "$ENVF" ML_SEAL_PUBLIC_KEY)
  SEC_PRESENT=no; [ -n "$(valof "$MAILER_ENV" ML_SEAL_SECRET_KEY)" ] && SEC_PRESENT=yes
  if [ "$SEC_PRESENT" = yes ]; then
    # the public half derived from the secret, read by PHP from the file itself (never through argv or the screen)
    DERIVED=$(php -r '$f = $argv[1]; foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) { if (str_starts_with($l, "ML_SEAL_SECRET_KEY=")) { $s = base64_decode(trim(substr($l, 19), "\"'"'"' "), true); } }
                      if (!isset($s) || $s === false || strlen($s) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) { exit(3); } echo base64_encode(sodium_crypto_box_publickey_from_secretkey($s));' "$MAILER_ENV") || {
      refuse "ML_SEAL_SECRET_KEY in $MAILER_ENV is not a libsodium box secret key (32 bytes, base64). Nothing was changed."; DERIVED=''; }
    if [ -z "$DERIVED" ]; then
      :
    elif [ -z "$PUB" ]; then
      echo "   the secret key is there and config/.env has no public key — the public half is derived from it (no new pair)"
      keyset "$ENVF" ML_SEAL_PUBLIC_KEY "$DERIVED"
    elif [ "$PUB" = "$DERIVED" ]; then
      echo "   the sealing pair is in place and its halves match — kept"
    else
      refuse "ML_SEAL_PUBLIC_KEY in config/.env is not the public half of the secret key in $MAILER_ENV." \
             "Credentials sealed under one cannot be opened by the other: find which pair sealed mailbox_credentials (restore the matching" \
             "file from backup) before going further. Nothing was changed."
    fi
  elif [ -n "$PUB" ]; then
    refuse "config/.env has ML_SEAL_PUBLIC_KEY but $MAILER_ENV has no secret key. Every credential sealed under that public key" \
           "needs its secret: restore $MAILER_ENV from backup. Only if no credential was ever sealed (no row in mailbox_credentials)" \
           "remove ML_SEAL_PUBLIC_KEY from config/.env yourself and run this again. Nothing was changed."
  else
    echo "+ make the pair: the secret half → $MAILER_ENV (not shown), the public half → $ENVF"
    if [ "$MODE" = apply ]; then
      umask 077
      PAIR=$(php -r '$k = sodium_crypto_box_keypair(); echo base64_encode(sodium_crypto_box_publickey($k)), " ", base64_encode(sodium_crypto_box_secretkey($k));')
      keyset "$MAILER_ENV" ML_SEAL_SECRET_KEY "${PAIR#* }" secret
      keyset "$ENVF" ML_SEAL_PUBLIC_KEY "${PAIR%% *}"
      unset PAIR
    fi
  fi
  # the door's key: one value in both files — kept when either already holds it
  MK=$(valof "$ENVF" ML_MAILER_KEY); MK2=$(valof "$MAILER_ENV" ML_MAILER_KEY)
  if [ -n "$MK" ] && [ -n "$MK2" ] && [ "$MK" != "$MK2" ]; then
    echo "   ML_MAILER_KEY differs between the two files — the mail service's copy is the one kept (the web side follows it)"
    keyset "$ENVF" ML_MAILER_KEY "$MK2" secret
  elif [ -n "$MK" ] || [ -n "$MK2" ]; then
    MK=${MK:-$MK2}
    keyset "$ENVF" ML_MAILER_KEY "$MK" secret
    keyset "$MAILER_ENV" ML_MAILER_KEY "$MK" secret
  else
    MK=$(openssl rand -hex 32)
    keyset "$ENVF" ML_MAILER_KEY "$MK" secret
    keyset "$MAILER_ENV" ML_MAILER_KEY "$MK" secret
  fi
  unset MK MK2
  # the part key (Phase 1, reading-and-rendering.md): signs the one-hour /p/ and /img/ links; the mail service mints, PHP verifies —
  # config/.env alone (both read it); made once, kept, never printed
  if [ -z "$(valof "$ENVF" ML_PART_KEY)" ]; then keyset "$ENVF" ML_PART_KEY "$(openssl rand -hex 32)" secret; fi
fi

# ------------------------------------------------------------------------------------------------------------------
# 4. THE STORAGE DIRECTORY (idempotent; before the first draft attachment). A draft's attachments wait in $APP/storage until the message is
#    sent (ML_STORAGE_DIR, default storage/; never under html/). www-data writes; the mail service reads them by GROUP malumail-mailer to build
#    the MIME and to APPEND a draft (setgid 2750: every file made there takes the group; a file must stay group-readable — slice 4 checks it).
STORE=$(valof "$ENVF" ML_STORAGE_DIR); STORE=${STORE:-storage}; case "$STORE" in /*) ;; *) STORE=$APP/$STORE ;; esac
echo "== 4. The storage directory $STORE"
run install -d -o www-data -g malumail-mailer -m 2750 "$STORE"
for d in files tmp; do run install -d -o www-data -g malumail-mailer -m 2750 "$STORE/$d"; done

# ------------------------------------------------------------------------------------------------------------------
# 5. THE CUSTOMER KEY (the owner's; design §6.1). MaluMail's customer key for the Mailbox MCP reaches EVERY mailbox of the MaluMail
#    customer — the served-domain list (Admin → Domains) is the application's wall. It lives in $MAILER_ENV only, as
#    MALUMAIL_MAILBOX_API_KEY. It is never printed, never in config/.env, never in the repository. (The notification key MALUMAIL_API_KEY in
#    config/.env is a different key: it only sends the bell's mail copies through /v1/send and opens no mailbox.)
echo "== 5. The MaluMail customer key → $MAILER_ENV (MALUMAIL_MAILBOX_API_KEY, never shown)"
if [ "$MODE" = apply ]; then
  CK=''
  if [ -n "$KEYFILE" ]; then
    [ -r "$KEYFILE" ] || refuse "cannot read $KEYFILE. Nothing more was changed."
    CK=$(grep -m1 -v '^[[:space:]]*\(#\|$\)' "$KEYFILE" | sed -E 's/^[A-Z_]+=//; s/^(["'"'"'])(.*)\1$/\2/; s/[[:space:]]+$//')
    [ -n "$CK" ] || refuse "$KEYFILE holds no key. Nothing more was changed."
  elif [ -z "$(valof "$MAILER_ENV" MALUMAIL_MAILBOX_API_KEY)" ] && [ -t 0 ]; then
    read -r -s -p "   MaluMail customer key (input hidden; Enter to skip): " CK; echo
  fi
  if [ -n "$CK" ]; then
    keyset "$MAILER_ENV" MALUMAIL_MAILBOX_API_KEY "$CK" secret
  elif [ -z "$(valof "$MAILER_ENV" MALUMAIL_MAILBOX_API_KEY)" ]; then
    echo "   NOTE: no customer key yet — the mail service starts, but administration (mailboxes, aliases, domains) waits for it:"
    echo "         sudo bash deploy/ROOT_STEPS.sh apply --customer-key-file <a file only root can read>"
  else
    echo "   the customer key is in place — kept"
  fi
  unset CK
  chown malumail-mailer:malumail-mailer "$MAILER_ENV"; chmod 0400 "$MAILER_ENV"
else
  echo "   (apply asks for it, or reads --customer-key-file; it never reaches the screen or config/.env)"
fi

# ------------------------------------------------------------------------------------------------------------------
# 6. THE INSTALLER (printed, never run here) — after 0–5, and not before Phase 2's shell exists: database and roles, config/.env, the
#    vhost, the units (the mail service and the image door among them — each skipped by its ConditionPathExists until its code exists:
#    services/mailer/ after the spike, services/images/ in slice 3), the registration (catalog kind ours — the row K43 seeded, kernel db/181 —,
#    /sso and /sso/logout, scope_kind none), the application token, skills imported, approval policies, the installing super-admin's admin
#    grant. Run it twice: the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 6. The installer (printed, not run)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com          (read-only)"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --hire-agents"
echo "   — no --grant-standing-departments: MaluMail is on request (D15); a super-admin grants the Mail user role to the people who"
echo "     use it. --hire-agents hires the Mail Expert and the Mail Clerk (weekdays 07:10, agents[].duty)."

# ------------------------------------------------------------------------------------------------------------------
# 7. THE OWNER'S (any time after 6):
echo "== 7. The owner's"
echo "   - DNS and TLS for webmail.subello.com (until then /etc/hosts maps it to this host)"
echo "   - MaluMail's items with the infrastructure side (design §12): MM2 before anything logs in from this server; MM1 before a"
echo "     person's existing mailbox opens here; MM3 before internal-looking mail counts as internal"
echo "   - Admin → Domains: which of the customer's domains this business serves and which are internal (the customer key's wall)"
echo "   - the connections a super-admin approves (bin/app_connection.php): the CRM and Help Desk reading correspondence_index"
echo "   - see the two loopback services:  systemctl status malumail-mailer malumail-images.socket malumail-images"
echo "     curl -s http://webmail.subello.com/api/v1/health    # mailer ok, images ok once their code exists"
