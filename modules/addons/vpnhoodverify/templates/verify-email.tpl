{* VpnHood! Verify — the gate page, and the only client-area page this addon serves.

   Every gated page redirects here, so this page has to carry the way out: a fresh
   confirmation link, because WHMCS's own expires after 60 minutes. Logout stays
   reachable for the same reason. *}

<div class="card">
  <div class="card-body">
    <h2 class="card-title">Confirm your email address</h2>

    {if $verified}
      <div class="alert alert-success" role="alert">
        Your address is confirmed. <a href="clientarea.php">Continue to your account</a>.
      </div>
    {else}

      {if $attempted}
        {if $resent}
          <div class="alert alert-success" role="alert">
            A new confirmation link is on its way to <strong>{$email|escape}</strong>.
            It is valid for 60 minutes.
          </div>
        {else}
          <div class="alert alert-danger" role="alert">
            We could not send the confirmation email just now. Please try again in a few
            minutes, or contact support if it keeps failing.
          </div>
        {/if}
      {/if}

      <p>
        Before we open your account, we need to know that
        <strong>{$email|escape}</strong> really belongs to you. Click the link in the
        email we sent and this page will step aside.
      </p>
      <p class="text-muted">
        Nothing has gone wrong with your account, and nothing further is owed. If the
        email has not arrived, check your spam folder — or send yourself a fresh link,
        as ours expires after 60 minutes.
      </p>

      <form method="post" action="index.php?m={$module|escape:'url'}">
        <input type="hidden" name="do" value="resend">
        <button type="submit" class="btn btn-primary">Send me a new link</button>
        <a href="logout.php" class="btn btn-default">Log out</a>
      </form>

    {/if}
  </div>
</div>
