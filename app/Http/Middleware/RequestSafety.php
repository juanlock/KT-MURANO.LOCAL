<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class RequestSafety
{
    /**
     * HTTP methods to inspect (skip GET/HEAD for perf).
     *
     * @var array<int, string>
     */
    protected array $inspectMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * URIs to skip entirely (wildcards supported via $request->is()).
     * Example: 'api/store/uploads', 'api/store/uploads/*'
     *
     * @var array<int, string>
     */
    protected array $skipUris = [
        // 'api/store/uploads',
        // 'api/store/uploads/*',
    ];

    /**
     * Dot-path prefixes to exclude from scanning (for known HTML fields).
     * Examples: '$.content_html', '$.page.body'
     *
     * @var array<int, string>
     */
    protected array $excludePaths = [
        // '$.content_html',
        // '$.email_template',
        // '$.page.body',
        // Invoice PDF Terms & Conditions intentionally uses {{COMPANY NAME}} /
        // {{ADDRESS COMPANY}} mustache-style placeholders (substituted server-side
        // in sale_pdf.blade.php), which otherwise trips the template-injection check below.
        '$.sale_pdf_terms_conditions',
        // Same field inside the Invoice PDF live preview, which posts the unsaved
        // Default-tab options nested under `company` (POST pdf_templates/{type}/preview).
        '$.company.sale_pdf_terms_conditions',
    ];

    /**
     * Fields that legitimately hold {{placeholder}} templates, per URI: only
     * the template-injection patterns are skipped there -- the HTML/JS checks
     * still apply. '*' in a path = any array index.
     * Sales Contracts: each line's invoice note ("Note: {{month}} installment
     * services"), filled per invoice by SalesContractBilling::renderNote()
     * (unknown placeholders are rejected by SalesContractController).
     *
     * @var array<string, array<int, string>>
     */
    protected array $templateFields = [
        'api/sales_contracts' => ['$.items.*.note', '$.invoice_note', '$.jobs_service_item'],
        // Sale / line notes and the service job title: placeholders filled on save.
        'api/sales' => ['$.notes', '$.details.*.note', '$.service_job.title'],
        'api/sales/*' => ['$.notes', '$.details.*.note', '$.service_job.title'],
        'api/sales_contracts/*' => ['$.items.*.note', '$.notes.*', '$.invoice_note', '$.jobs_service_item'],
    ];

    /**
     * Fields that ARE HTML, per URI: not scanned here because their endpoint
     * checks the HTML itself. Website -> Pages -> Page sections: a section's
     * code (frames, the site's own scripts) -- SitePageEditor::guardSection()
     * refuses new scripts / event handlers / javascript: from a company admin.
     *
     * @var array<string, array<int, string>>
     */
    protected array $htmlFields = [
        'api/admin/store/site-pages/*/sections/*' => ['$.html'],
    ];

    /** Regexes of the template-field paths that apply to this request. */
    private array $templatePathRegexes = [];

    /**
     * Max characters to scan per string (perf guard).
     */
    protected int $maxScanLen = 4096;

    public function handle(Request $request, Closure $next): Response
    {
        // Only inspect selected HTTP methods
        if (! in_array($request->getMethod(), $this->inspectMethods, true)) {
            return $next($request);
        }

        // Skip selected URIs (if any)
        foreach ($this->skipUris as $uri) {
            if ($request->is($uri)) {
                return $next($request);
            }
        }

        $this->templatePathRegexes = [];
        foreach ($this->templateFields as $uri => $paths) {
            if ($request->is($uri)) {
                foreach ($paths as $p) {
                    $this->templatePathRegexes[] = '/^'.str_replace('\*', '[^.]+', preg_quote($p, '/')).'$/';
                }
            }
        }

        // Build a copy of all request data and drop excluded paths
        $payload = $request->all();
        foreach ($this->excludePaths as $prefix) {
            $this->stripExcludedPrefix($payload, $prefix);
        }
        foreach ($this->htmlFields as $uri => $paths) {
            if ($request->is($uri)) {
                foreach ($paths as $prefix) {
                    $this->stripExcludedPrefix($payload, $prefix);
                }
            }
        }

        // Walk & check
        if ($hit = $this->walkAndDetect($payload)) {
            return response()->json([
                'status' => false,
                'field' => $hit['path'],        // e.g., "$.items.3.name"
                'message' => 'Unsafe input detected. HTML/JS and event attributes are not allowed.',
            ], 422);
        }

        return $next($request);
    }

    /**
     * Recursively walk any PHP value and check all strings.
     * Returns ['path' => '$.a.b.0.c', 'value' => '...'] on first hit, or null if safe.
     */
    private function walkAndDetect(mixed $value, string $path = '$'): ?array
    {
        // Skip uploaded files
        if ($value instanceof UploadedFile) {
            return null;
        }

        if (is_string($value)) {
            $allowTemplates = false;
            foreach ($this->templatePathRegexes as $re) {
                if (preg_match($re, $path)) {
                    $allowTemplates = true;
                    break;
                }
            }
            if ($this->isTextUnsafe($value, $allowTemplates)) {
                return ['path' => $path, 'value' => $value];
            }

            return null;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $childPath = $path.'.'.(is_int($k) ? $k : $k);
                if ($hit = $this->walkAndDetect($v, $childPath)) {
                    return $hit;
                }
            }

            return null;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                $childPath = $path.'.'.$k;
                if ($hit = $this->walkAndDetect($v, $childPath)) {
                    return $hit;
                }
            }

            return null;
        }

        // numbers/bools/null/resources: ignore
        return null;
    }

    /**
     * Detects common XSS vectors (NO mutation).
     */
    private function isTextUnsafe(string $s, bool $allowTemplates = false): bool
    {
        if ($s === '') {
            return false;
        }

        // Perf guard
        if (mb_strlen($s) > $this->maxScanLen) {
            $s = mb_substr($s, 0, $this->maxScanLen);
        }

        $patterns = [
            // Raw tags
            '/<\/?\s*script\b/i',
            '/<\/?\s*svg\b/i',
            '/<\/?\s*iframe\b/i',
            '/<\/?\s*details\b/i',
            '/<\/?\s*object\b/i',
            '/<\/?\s*embed\b/i',
            '/<\/?\s*link\b/i',
            '/<\/?\s*meta\b/i',

            // Encoded tags (common encodings)
            '/&lt;\s*script\b/i',
            '/&lt;\s*svg\b/i',
            '/&#x3C;\s*script\b/i', // &#x3C; = <
            '/&#60;\s*script\b/i',  // &#60;  = <

            // Event handlers (onclick=, onerror=, ontoggle=, onload=, ...)
            '/\bon\w+\s*=/i',

            // Dangerous URL schemes
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/data\s*:\s*text\/html/i',

        ];
        // Template-style injections (optional—keep if relevant to your stack);
        // skipped on fields declared in $templateFields.
        if (! $allowTemplates) {
            $patterns[] = '/\{\{.*\}\}/s';
            $patterns[] = '/\$\{.*\}/s';
        }

        foreach ($patterns as $re) {
            if (preg_match($re, $s)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove values whose dot-path starts with a given prefix.
     * Example prefix: '$.content_html' will exclude that subtree.
     */
    private function stripExcludedPrefix(mixed &$value, string $prefix, string $path = '$'): void
    {
        // Exact or prefix match → null out
        if ($path === $prefix || str_starts_with($path.'.', $prefix.'.')) {
            $value = null;

            return;
        }

        if (is_array($value)) {
            foreach ($value as $k => &$v) {
                $this->stripExcludedPrefix($v, $prefix, $path.'.'.(is_int($k) ? $k : $k));
            }

            return;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->{$k} = $v;
                $this->stripExcludedPrefix($value->{$k}, $prefix, $path.'.'.$k);
            }
        }
    }
}
