<p>Click the button below to download your premium codes as a CSV file.</p>

{* See clientarea.tpl: partner clients need the ORDER id their API takes, not this page's
   service id. Empty for every other client. *}
{if $partnerOrderId}
    <p class="text-muted">
        <strong>VpnHood order #{$partnerOrderId|escape}</strong> — quote this in support, and
        send it as <code>upstreamOrderId</code> in the Partner API. The id in this page's
        address is the service id and is not accepted there.
    </p>
{/if}

<button id="getPremiumCode" class="btn btn-success">Download CSV file</button>

<div id="resultBox" style="margin-top: 15px;"></div>

{literal}
    <script src="modules/servers/vpnhoodstore/assets/ajax-request.js"></script>
{/literal}