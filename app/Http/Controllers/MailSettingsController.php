<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailSettingsController extends BaseController
{
    use ScopesToCompany;

    // -------------- Get mail_settings ---------------\\

    public function get_config_mail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'mail_settings', Setting::class);

        $companyId = $this->resolveActingCompanyId($request);
        $server = Server::forCompany($companyId);

        // Same fallback chain test_config_mail uses when no destination is
        // explicitly given, exposed here so the frontend can pre-fill the
        // Test button's destination field with what would actually be used.
        $user = $request->user('api');
        $settings = Setting::forCompany($companyId);
        $defaultTestEmail = ($user && $user->email) ? $user->email : ($settings->email ?? null);

        return response()->json([
            'server' => $server,
            'default_test_email' => $defaultTestEmail,
            // Admin notices (bookings, POS register): who gets them, and the
            // SuperAdmin-copy switch (only a SuperAdmin may see / change it).
            'notice_recipients' => array_values(array_filter(\App\Support\CompanyMail::recipients($companyId), fn ($r) => ! $r['copy'])),
            'notify_superadmin_copy' => (bool) ($settings->notify_superadmin_copy ?? false),
        ], 200);
    }

    // -------------- Update mail settings ---------------\\

    public function update_config_mail(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'mail_settings', Setting::class);

        $companyId = $this->resolveActingCompanyId($request);
        // Company boundary: $id alone isn't enough -- without this check any
        // admin could pass another company's server id and edit its SMTP
        // credentials. Only ever update the row that actually belongs to the
        // acting company (Server::forCompany() already guarantees $id === the
        // company's own row for a same-company request, this is belt & braces).
        $server = Server::where('id', $id)->where('company_id', $companyId)->first();
        abort_unless($server, 404);

        $server->update([
            'mail_mailer' => $request['mail_mailer'],
            'host' => $request['host'],
            'port' => $request['port'],
            'sender_name' => $request['sender_name'],
            'sender_email' => $request['sender_email'],
            'username' => $request['username'],
            'password' => $request['password'],
            'encryption' => $request['encryption'],
        ]);

        // "Send a copy to the SuperAdmin" -- the SuperAdmin decides, per company.
        if ($request->has('notify_superadmin_copy') && optional($request->user('api'))->type === 'SuperAdmin') {
            $settings = Setting::forCompany($companyId);
            $settings->notify_superadmin_copy = $request->boolean('notify_superadmin_copy');
            $settings->save();
        }

        return response()->json(['success' => true]);

    }

    // -------------- Test mail settings ---------------\\

    public function test_config_mail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'mail_settings', Setting::class);

        $companyId = $this->resolveActingCompanyId($request);
        // Load mail configuration from DB, for the acting company specifically.
        $this->Set_config_mail($companyId);

        $user = $request->user('api');
        $settings = Setting::forCompany($companyId);
        $server = Server::forCompany($companyId);

        // Prefer explicitly provided test email, then current user email, then company email
        $to = $request->input('email')
            ?: ($user && $user->email ? $user->email : null)
            ?: ($settings && $settings->email ? $settings->email : null);

        if (! $to) {
            return $this->sendError('No destination email address available for test.');
        }

        try {
            // Reflects the acting company's own name, not a hardcoded
            // brand -- this page is per-company now, so company 2's test
            // email shouldn't say "KcolTech".
            $companyLabel = $settings && $settings->CompanyName ? $settings->CompanyName.' - Murano' : 'Murano';
            Mail::raw("This is a test email to verify your mail configuration in {$companyLabel}.", function ($message) use ($to, $settings, $server) {
                $message->to($to)
                    ->subject('Test Mail Configuration');

                // Prefer sender_email from server, then fallback to settings email
                $fromEmail = ($server && $server->sender_email) 
                    ? $server->sender_email 
                    : ($settings && $settings->email ? $settings->email : null);
                
                if ($fromEmail) {
                    $fromName = ($server && $server->sender_name) 
                        ? $server->sender_name 
                        : ($settings && $settings->CompanyName ? $settings->CompanyName : 'KcolTech - Murano');
                    $message->from($fromEmail, $fromName);
                }
            });

            return $this->sendResponse(null, 'Test email sent successfully to '.$to);
        } catch (\Exception $e) {
            return $this->sendError('Failed to send test email.', $e->getMessage());
        }
    }
}
