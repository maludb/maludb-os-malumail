-- 016: SLICE 1's small writes (docs/build-specs/domains-mailboxes.md) — what the administration screens change that db/006–015 had no
-- function for, so every administrative write still passes a right in SQL: a mailbox's quota as recorded here, the cache of MaluMail's
-- forwarding addresses (ml_aliases — MaluMail is the record; the cache is what the screens and §5's internal test read), and a served
-- domain's SPF/DKIM/DMARC flags as last read. Additive.
BEGIN;

-- The quota MaluMail was just told (the mail service's set_mailbox_quota), recorded on the mailbox.
CREATE OR REPLACE FUNCTION ml_mailbox_quota_set(p_mailbox bigint, p_by bigint, p_gib integer) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_right(p_by, 'mailboxes.manage');
    IF p_gib IS NULL OR p_gib < 1 OR p_gib > 50 THEN
        RAISE EXCEPTION 'A quota is 1 to 50 GiB' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE mailboxes SET quota_bytes = p_gib::bigint * 1073741824 WHERE id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
END$$;

-- A forwarding address as MaluMail now holds it (after create_alias / update_alias, or a list_aliases read): upserted by address, on a
-- served domain only. Answers the cache row's id.
CREATE OR REPLACE FUNCTION ml_alias_cache_put(p_by bigint, p_address text, p_destinations text[]) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE did bigint; aid bigint; addr text := lower(btrim(p_address));
BEGIN
    PERFORM ml_require_right(p_by, 'aliases.manage');
    SELECT id INTO did FROM ml_domains WHERE domain = ml_address_domain(addr) AND served;
    IF did IS NULL THEN
        RAISE EXCEPTION 'MaluMail serves no domain % here: a Mail admin adds it first', ml_address_domain(addr) USING ERRCODE = 'check_violation';
    END IF;
    IF cardinality(p_destinations) IS NULL OR cardinality(p_destinations) = 0 THEN
        RAISE EXCEPTION 'A forwarding address forwards to at least one address' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO ml_aliases (domain_id, address, destinations, created_by, synced_at)
    VALUES (did, addr, (SELECT array_agg(lower(btrim(x))) FROM unnest(p_destinations) x)::citext[], p_by, now())
    ON CONFLICT (address) DO UPDATE SET destinations = EXCLUDED.destinations, synced_at = now(), active = true
    RETURNING id INTO aid;
    RETURN aid;
END$$;

CREATE OR REPLACE FUNCTION ml_alias_cache_drop(p_by bigint, p_address text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_right(p_by, 'aliases.manage');
    DELETE FROM ml_aliases WHERE address = lower(btrim(p_address));
    RETURN FOUND;
END$$;

-- MaluMail's SPF/DKIM/DMARC flags for a served domain, as list_hosted_domains last answered them.
CREATE OR REPLACE FUNCTION ml_domain_flags_save(p_by bigint, p_domain text, p_spf boolean, p_dkim boolean, p_dmarc boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_right(p_by, 'domains.manage');
    UPDATE ml_domains SET spf_ok = p_spf, dkim_ok = p_dkim, dmarc_ok = p_dmarc, flags_read_at = now() WHERE domain = lower(btrim(p_domain));
END$$;

REVOKE ALL ON FUNCTION ml_mailbox_quota_set(bigint, bigint, integer), ml_alias_cache_put(bigint, text, text[]), ml_alias_cache_drop(bigint, text),
    ml_domain_flags_save(bigint, text, boolean, boolean, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_mailbox_quota_set(bigint, bigint, integer), ml_alias_cache_put(bigint, text, text[]), ml_alias_cache_drop(bigint, text),
    ml_domain_flags_save(bigint, text, boolean, boolean, boolean) TO malumail_rw;

COMMIT;
