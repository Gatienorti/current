@if($debugPrompt)
<dialog class="debug-dialog" data-debug-dialog aria-labelledby="debug-prompt-title" aria-describedby="debug-prompt-description">
    <div class="panel-heading"><h2 id="debug-prompt-title">Debugging prompt</h2><button class="button secondary small" type="button" data-debug-close autofocus>Close</button></div>
    <div class="debug-dialog-body">
        <p id="debug-prompt-description" class="subtle">Ready for your coding agent. Review the captured context before sharing. Nothing is sent automatically.</p>
        <textarea class="debug-prompt-text" data-debug-text aria-label="Debugging prompt" readonly spellcheck="false">{{ $debugPrompt }}</textarea>
        <div class="debug-dialog-actions"><span role="status" aria-live="polite" data-debug-status></span><button class="button primary" type="button" data-debug-copy aria-label="Copy debugging prompt" title="Copy debugging prompt">@include('horizon-lens::icon', ['iconName' => 'copy'])<span>Copy prompt</span></button></div>
    </div>
</dialog>
@endif
