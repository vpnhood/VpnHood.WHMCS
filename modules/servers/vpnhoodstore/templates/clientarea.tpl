{* Store-purchase notice. Renders only for services the vpnhoodiap addon provisioned
   from an app-store purchase (they carry the 'purchasedVia' service property); every
   other service on the install renders exactly as before. *}
{if $purchasedViaLabel}
    <div class="alert alert-info">
        <p>
            <strong>Purchased via {$purchasedViaLabel|escape}.</strong>
            This subscription was bought in the app, so {$purchasedViaLabel|escape} handles the billing.
        </p>
        <p class="mb-0">
            No payment was made to us for this order, and none will be taken by us for renewals —
            {$purchasedViaLabel|escape} charges you directly. To cancel the subscription or ask for a
            refund, do it through {$purchasedViaLabel|escape}; we cannot cancel or refund it here.
        </p>
    </div>
{/if}

<p>You can get your premium code using the button below.</p>

<button id="getPremiumCode" class="btn btn-success">Get Premium Code</button>

<div id="resultBox" style="margin-top: 15px;"></div>

{literal}
    <script src="modules/servers/vpnhoodstore/assets/ajax-request.js"></script>
{/literal}