<?php /** The command bar (chat-actions): fixed bottom, every screen; above the tab bar on a phone; the reply expands upward. POST /assistant/ask → the kernel's chat endpoint. A refusal (4xx) or the kernel down (503) is swapped into the bar too — the kernel's words, or "The kernel is not reachable right now." */ ?>
<div id="assistant-bar" class="assistant-bar">
    <div id="assistant-reply" class="assistant-reply" aria-live="polite" hx-on::before-swap="if(event.detail.xhr.status>=400){event.detail.shouldSwap=true;event.detail.isError=false;}"></div>
    <form id="assistant-form" class="assistant-form"
          hx-post="/assistant/ask.php"
          hx-target="#assistant-reply"
          hx-swap="innerHTML"
          hx-indicator="#assistant-send"
          hx-vals='js:{screen: (document.getElementById("page-content")||{}).dataset?.screen || "",
                       entity: (document.getElementById("page-content")||{}).dataset?.entity || "",
                       record_id: (document.getElementById("page-content")||{}).dataset?.recordId || "",
                       mailbox: (document.getElementById("page-content")||{}).dataset?.mailbox || ""}'
          hx-on::after-request="if(event.detail.successful){this.reset();this.classList.remove('in-use');document.getElementById('assistant-input').focus();}">
        <div class="input-group">
            <span class="input-group-text bg-transparent border-0"><i class="feather-message-circle text-muted"></i></span>
            <input type="text" id="assistant-input" name="utterance" class="form-control border-0"
                   placeholder="Ask about your mail, or tell it what to do…" autocomplete="off" maxlength="2000" aria-label="Ask MaluMail" />
            <button type="submit" id="assistant-send" class="btn btn-primary" aria-label="Send">
                <i class="feather-send"></i>
            </button>
        </div>
    </form>
</div>
<script>
    (function () {
        document.addEventListener('keydown', function (e) {
            var input = document.getElementById('assistant-input');
            if (!input) return;
            var tag = (e.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;
            if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing)) {
                e.preventDefault();
                input.focus();
            }
        });
    })();
</script>
