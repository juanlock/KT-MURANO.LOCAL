<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Models\EcommerceClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Recipients for a document email (sale / reminder / quotation / purchase):
 * the `emails` the user confirmed in the list page's recipients modal, or
 * the party's own email when none were sent. Any address not yet on the
 * party (customer or supplier) is saved to it -- as the main `email` when
 * the party has none (and $mainEmailTaken says it's free), otherwise into
 * its `additional_emails` JSON column.
 */
trait ResolvesEmailRecipients
{
    /**
     * @param  callable|null  $mainEmailTaken  fn(string $email): bool -- whether the
     *                                         address may NOT become the main email
     *                                         (e.g. clients.email is unique).
     * @return array|string list of recipients, or an error message
     */
    protected function resolveEmailRecipients(Request $request, Model $party, ?callable $mainEmailTaken = null)
    {
        $requested = $request->input('emails');
        if (! is_array($requested) || ! count($requested)) {
            return $party->email ? [$party->email] : 'This contact has no email address on file.';
        }

        $recipients = [];
        foreach ($requested as $raw) {
            $addr = trim((string) $raw);
            if ($addr === '') {
                continue;
            }
            if (! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                return 'Invalid email address: '.$addr;
            }
            $recipients[strtolower($addr)] ??= $addr;
        }
        if (! $recipients) {
            return 'Add at least one email address.';
        }
        if (count($recipients) > 10) {
            return 'You can send to at most 10 email addresses at once.';
        }

        $additional = array_values(array_filter((array) ($party->additional_emails ?? [])));
        $known = array_map('strtolower', array_filter(array_merge([$party->email], $additional)));
        $changed = false;
        foreach ($recipients as $lower => $addr) {
            if (in_array($lower, $known, true)) {
                continue;
            }
            if (empty($party->email) && ! ($mainEmailTaken && $mainEmailTaken($addr))) {
                $party->email = $addr;
            } else {
                $additional[] = $addr;
            }
            $known[] = $lower;
            $changed = true;
        }
        if ($changed) {
            $party->additional_emails = $additional ?: null;
            $party->save();
        }

        return array_values($recipients);
    }

    // Customer variant: clients.email is unique across customers and store
    // logins, so a new address only becomes the main email when it's free.
    protected function resolveClientEmailRecipients(Request $request, Client $client)
    {
        return $this->resolveEmailRecipients($request, $client, fn ($addr) =>
            Client::where('email', $addr)->whereNull('deleted_at')->where('id', '!=', $client->id)->exists()
            || EcommerceClient::where('email', $addr)->whereNull('deleted_at')->where('client_id', '!=', $client->id)->exists());
    }

    // Trimmed, de-duplicated (case-insensitive) extra emails, without the
    // main email; null when empty. Used by the customer/supplier forms.
    protected function normalizeAdditionalEmails($emails, $mainEmail)
    {
        $seen = [strtolower(trim((string) $mainEmail))];
        $out = [];
        foreach ((array) $emails as $e) {
            $e = trim((string) $e);
            if ($e === '' || in_array(strtolower($e), $seen, true)) {
                continue;
            }
            $seen[] = strtolower($e);
            $out[] = $e;
        }

        return $out ?: null;
    }
}
