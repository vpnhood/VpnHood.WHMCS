<?php
// Puts the Premium Code into the welcome email of every vpnhoodstore product: a block showing
// {$premium_code}, which includes/hooks/vpnhoodstore-welcome-code.php fills as the mail goes out.
// The code stands on a line of its own, left to right, so a right-to-left mail cannot reorder its
// groups; no invisible direction mark is added, as it would be copied with the code.
// Every language of the template gets it, before its "Thank you" (else its signature) paragraph.
// Run it again after changing the block: a template holding an older block gets this one, one
// already holding this block is left as it is.
// usage: ssh <user>@... 'cd <webroot> && php' < welcome-code-template.php

require 'init.php';

use WHMCS\Database\Capsule;

$html = <<<'HTML'
{if $premium_code}
<p>
Here is your Premium Code:
</p>
<p dir="ltr" style="font-size: 1.2em;">
<strong>{$premium_code}</strong>
</p>
<p>
Just enter it in the VpnHood app and enjoy Premium. Please keep this email somewhere safe: it is your copy of the code, and we can't look it up for you if your account is ever deleted.
</p>
{/if}

HTML;
$text = <<<'TEXT'
{if $premium_code}
Here is your Premium Code:

{$premium_code}

Just enter it in the VpnHood app and enjoy Premium. Please keep this email somewhere safe: it is your copy of the code, and we can't look it up for you if your account is ever deleted.
{/if}

TEXT;

$templateIds = Capsule::table('tblproducts')->where('servertype', 'vpnhoodstore')->where('welcomeemail', '>', 0)
    ->distinct()->pluck('welcomeemail')->all();
$names = Capsule::table('tblemailtemplates')->whereIn('id', $templateIds ?: [0])->pluck('name')->all();
if (!$names) {
    echo "no vpnhoodstore product has a welcome email\n";
    exit(1);
}

foreach (Capsule::table('tblemailtemplates')->whereIn('name', $names)->where('type', 'product')->get() as $template) {
    $label = "'{$template->name}'" . ($template->language === '' ? '' : " ({$template->language})");
    $message = (string) $template->message;
    $block = (int) $template->plaintext === 1 ? $text : $html;
    if (str_contains($message, $block)) {
        echo "$label: already shows the code\n";
        continue;
    }
    // an older block of ours: replaced where it stands
    $old = '/\{if \$premium_code\}.*?\{\/if\}\r?\n?/s';
    if (preg_match($old, $message)) {
        $updated = preg_replace($old, str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $message, 1);
        Capsule::table('tblemailtemplates')->where('id', $template->id)->update(['message' => $updated]);
        echo "$label: code block updated\n";
        continue;
    }
    $at = false;
    foreach (['Thank you', '{$signature}'] as $anchor) {
        $pos = strpos($message, $anchor);
        if ($pos !== false) {
            $paragraph = (int) $template->plaintext === 1 ? false : strrpos(substr($message, 0, $pos), '<p');
            $at = $paragraph === false ? $pos : $paragraph;
            break;
        }
    }
    $updated = $at === false ? rtrim($message) . "\n" . $block : substr($message, 0, $at) . $block . substr($message, $at);
    Capsule::table('tblemailtemplates')->where('id', $template->id)->update(['message' => $updated]);
    echo "$label: code added\n";
}
