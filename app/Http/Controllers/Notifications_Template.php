<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Models\SMSMessage;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Http\Request;

class Notifications_Template extends Controller
{
    use ScopesToCompany;

    // -------------- get_sms_template ---------------\\

    public function get_sms_template(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'sms_templates', Setting::class);

        $locale = $request->query('locale', 'en');

        // ---get sms body for Sale

        $get_type_sale = SMSMessage::where('name', 'sale')->where('locale', $locale)->where('deleted_at', '=', null)->first();

        if ($get_type_sale) {
            $sms_body_sale = $get_type_sale->text;
        } else {
            $sms_body_sale = '';
        }

        // ---get sms body for quotation

        $get_type_quotation = SMSMessage::where('name', 'quotation')->where('locale', $locale)->where('deleted_at', '=', null)->first();

        if ($get_type_quotation) {
            $sms_body_quotation = $get_type_quotation->text;
        } else {
            $sms_body_quotation = '';
        }

        // ---get sms body for payment_received

        $get_type_payment_received = SMSMessage::where('name', 'payment_received')->where('locale', $locale)->where('deleted_at', '=', null)->first();

        if ($get_type_payment_received) {
            $sms_body_payment_received = $get_type_payment_received->text;
        } else {
            $sms_body_payment_received = '';
        }

        // ---get sms body for purchase

        $get_type_purchase = SMSMessage::where('name', 'purchase')->where('locale', $locale)->where('deleted_at', '=', null)->first();

        if ($get_type_purchase) {
            $sms_body_purchase = $get_type_purchase->text;
        } else {
            $sms_body_purchase = '';
        }

        // ---get sms body for payment_sent

        $get_type_payment_sent = SMSMessage::where('name', 'payment_sent')->where('locale', $locale)->where('deleted_at', '=', null)->first();

        if ($get_type_payment_sent) {
            $sms_body_payment_sent = $get_type_payment_sent->text;
        } else {
            $sms_body_payment_sent = '';
        }

        // ---get_type_subscription_reminder

        $get_type_subscription_reminder = SMSMessage::where('name', 'subscription_reminder')->where('locale', $locale)->where('deleted_at', '=', null)->first();

        if ($get_type_subscription_reminder) {
            $sms_body_subscription_reminder = $get_type_subscription_reminder->text;
        } else {
            $sms_body_subscription_reminder = '';
        }

        $get_type_asset_validation_due = SMSMessage::where('name', 'asset_validation_due')->where('locale', $locale)->where('deleted_at', '=', null)->first();
        if ($get_type_asset_validation_due) {
            $sms_body_asset_validation_due = $get_type_asset_validation_due->text;
        } else {
            $sms_body_asset_validation_due = '';
        }

        return response()->json([
            'sms_body_sale' => $sms_body_sale,
            'sms_body_quotation' => $sms_body_quotation,
            'sms_body_payment_received' => $sms_body_payment_received,
            'sms_body_purchase' => $sms_body_purchase,
            'sms_body_payment_sent' => $sms_body_payment_sent,
            'sms_body_subscription_reminder' => $sms_body_subscription_reminder,
            'sms_body_asset_validation_due' => $sms_body_asset_validation_due,
        ], 200);

    }

    // -------------- update_sms_body ---------------\\

    public function update_sms_body(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'sms_templates', Setting::class);

        $locale = $request->input('locale', $request->json('locale', 'en'));

        $smsMessage = SMSMessage::firstOrNew(['name' => $request['sms_body_type'], 'locale' => $locale]);
        $smsMessage->name = $request['sms_body_type'];
        $smsMessage->locale = $locale;
        $smsMessage->text = $request['sms_body'];
        $smsMessage->save();

        return response()->json(['success' => true]);

    }

    // -------------- get_emails_template ---------------\\

    public function get_emails_template(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'notification_template', Setting::class);

        $companyId = $this->resolveActingCompanyId($request);
        $locale = $request->query('locale', 'en');

        // Each lookup is company-scoped first, falling back to the shared
        // template pool (EmailMessage::getForLocale) so a company that
        // hasn't customized a template yet still sees real starter content
        // to edit, instead of a blank field -- same effective content real
        // outgoing emails would use for this company (see the callers of
        // EmailMessage::getForLocale() app-wide).
        $names = ['sale', 'quotation', 'payment_received', 'purchase', 'payment_sent', 'booking', 'asset_validation_due', 'payment_reminder'];
        $result = [];
        foreach ($names as $name) {
            $message = EmailMessage::getForLocale($name, $locale, $companyId);
            $result[$name] = [
                'subject' => $message->subject ?? '',
                'body' => $message->body ?? '',
            ];
        }

        return response()->json($result, 200);

    }

    // -------------- update_custom_email ---------------\\

    public function update_custom_email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'notification_template', Setting::class);

        $requestData = $request->json();
        $email_type = $requestData->get('email_type');

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', 'div[style],h1,h2,h3,h4,h5,h6,p[style],b,strong,i,em,u,ul,ol,li,br,hr,pre,blockquote,span[style]');
        $config->set('CSS.AllowedProperties', 'font-size, color, background-color, text-align');
        $purifier = new HTMLPurifier($config);

        $custom_email_body = $purifier->purify($requestData->get('custom_email_body'));

        $custom_email_subject = $purifier->purify($requestData->get('custom_email_subject'));

        $locale = $requestData->get('locale', 'en');
        $companyId = $this->resolveActingCompanyId($request);

        // Saves the ACTING COMPANY's own copy -- never the shared 999 pool
        // row or another company's row -- so customizing a template here
        // can never affect any other company.
        $emailMessage = EmailMessage::firstOrNew(['company_id' => $companyId, 'name' => $email_type, 'locale' => $locale]);
        $emailMessage->company_id = $companyId;
        $emailMessage->name = $email_type;
        $emailMessage->locale = $locale;
        $emailMessage->subject = $custom_email_subject;
        $emailMessage->body = $custom_email_body;
        $emailMessage->save();

        return response()->json(['success' => true]);

    }
}
