<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the Intranet module (staff only, per company):
 *  - permission gate: `intranet_view` to read, `intranet_manage` to manage
 *    (manage implies view); SuperAdmin passes both; `customer` accounts never.
 *  - the acting company comes from resolveActingCompanyId() (SuperAdmin
 *    follows the header company switcher, everyone else is locked to their
 *    own company), and a record of another company is a 404.
 *  - rich text (news bodies, static pages) is purified on save.
 */
abstract class IntranetBaseController extends Controller
{
    use ScopesToCompany;

    protected function userCan(Request $request, string $permission): bool
    {
        $user = $request->user('api');
        if (! $user || strtolower((string) $user->type) === 'customer') {
            return false;
        }
        if ($user->type === 'SuperAdmin') {
            return true;
        }
        $names = $user->roles()->with('permissions:id,name')->get()->flatMap(fn ($r) => $r->permissions->pluck('name'));

        return $names->contains($permission) || ($permission === 'intranet_view' && $names->contains('intranet_manage'));
    }

    /** Company id for a read (intranet_view) request. */
    protected function viewCompany(Request $request): int
    {
        abort_unless($this->userCan($request, 'intranet_view'), 403, 'You do not have access to the intranet.');
        $companyId = (int) $this->resolveActingCompanyId($request);
        \App\Support\ModuleFlags::abortUnlessEnabled($companyId, 'intranet');

        return $companyId;
    }

    /** Company id for a management (intranet_manage) request. */
    protected function manageCompany(Request $request): int
    {
        abort_unless($this->userCan($request, 'intranet_manage'), 403, 'You cannot manage the intranet.');
        $companyId = (int) $this->resolveActingCompanyId($request);
        \App\Support\ModuleFlags::abortUnlessEnabled($companyId, 'intranet');

        return $companyId;
    }

    /** @template T of Model  @param class-string<T> $class  @return T */
    protected function own(string $class, int $companyId, $id)
    {
        $row = $class::whereKey((int) $id)->first();
        abort_unless($row && (int) $row->company_id === $companyId, 404);

        return $row;
    }

    /** Rich text from the Quill editor -> safe HTML (no scripts/handlers/iframes). */
    protected function purify(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p[class|style],br,hr,h1[class],h2[class],h3[class],h4[class],h5[class],h6[class],'
            .'b,strong,i,em,u,s,strike,sub,sup,span[class|style],div[class|style],blockquote,pre[class],code,'
            .'ul[class],ol[class],li[class|data-list],a[href|target|rel|title],img[src|alt|title|width|height|style],'
            .'table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan]');
        $config->set('CSS.AllowedProperties', 'color,background-color,text-align,font-weight,font-style,text-decoration,width,height,max-width');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedClasses', null);
        $config->set('Attr.DefaultImageAlt', '');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true]);
        $config->set('HTML.DefinitionID', 'intranet-rte');
        $config->set('HTML.DefinitionRev', 1);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('li', 'data-list', 'Enum#bullet,ordered,checked,unchecked');
        }

        return (new HTMLPurifier($config))->purify($html);
    }

    /** Short plain-text preview of a rich-text body. */
    protected function plainExcerpt(?string $html, int $len = 220): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > $len ? rtrim(mb_substr($text, 0, $len - 1)).'…' : $text;
    }

    /** Delete a public file only if it sits inside this company's intranet folder. */
    protected function unlinkPublic(int $companyId, ?string $rel, string $module): void
    {
        if ($rel && str_starts_with($rel, 'files/company_'.$companyId.'/intranet/'.$module.'/') && ! str_contains($rel, '..') && is_file(public_path($rel))) {
            @unlink(public_path($rel));
        }
    }
}
