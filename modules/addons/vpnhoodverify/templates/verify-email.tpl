{* VpnHood! Verify — the gate page, and the only client-area page this addon serves.

   Every gated page redirects here, so this page has to carry the way out: a fresh
   confirmation link, because WHMCS's own expires after 60 minutes. Logout stays
   reachable for the same reason.

   $invoiceId is set when the client arrived here from checkout: their order and
   invoice exist, payment is what waits on the confirmation. *}

<div class="card">
  <div class="card-body">
    <h2 class="card-title">Confirm your email address</h2>

    {if $verified}
      <div class="alert alert-success" role="alert">
        Your address is confirmed.
        {if $invoiceId > 0}
          <a href="viewinvoice.php?id={$invoiceId}">Continue to your invoice</a> to complete your order.
        {else}
          <a href="clientarea.php">Continue to your account</a>.
        {/if}
      </div>
    {else}

      {if $attempted}
        {if $resent}
          <div class="alert alert-success" role="alert">
            A new confirmation link is on its way to <strong>{$email|escape}</strong>.
            It is valid for 60 minutes. If it has not shown up within a minute or two,
            <strong>check your spam or junk folder</strong>.
          </div>
        {else}
          <div class="alert alert-danger" role="alert">
            We could not send the confirmation email just now. Please try again in a few
            minutes, or contact support if it keeps failing.
          </div>
        {/if}
      {/if}

      {if $invoiceId > 0}
        <p>
          Your order is saved and nothing has been charged. Before we can take payment,
          we need to know that <strong>{$email|escape}</strong> really belongs to you —
          it is where your access details will go.
        </p>
        <p>
          Click the link in the email we sent; your invoice will then be waiting under
          Billing in your account, and this page will point you to it.
        </p>
        <p>
          <strong>Can't find the email? Check your spam or junk folder</strong> — our
          messages sometimes land there. If it is still missing, send yourself a fresh
          link below; ours expires after 60 minutes.
        </p>
      {else}
        <p>
          Before we open your account, we need to know that
          <strong>{$email|escape}</strong> really belongs to you. Click the link in the
          email we sent and this page will step aside.
        </p>
        <p class="text-muted">
          Nothing has gone wrong with your account, and nothing further is owed.
        </p>
        <p>
          <strong>Can't find the email? Check your spam or junk folder</strong> — our
          messages sometimes land there. If it is still missing, send yourself a fresh
          link below; ours expires after 60 minutes.
        </p>
      {/if}

      <form method="post" action="index.php?m={$module|escape:'url'}">
        <input type="hidden" name="do" value="resend">
        {if $invoiceId > 0}<input type="hidden" name="invoice" value="{$invoiceId}">{/if}
        <button type="submit" class="btn btn-primary">Send me a new link</button>
        <a href="logout.php" class="btn btn-default">Log out</a>
      </form>

    {/if}
  </div>
</div>
