<?php

$laravelVersion = '8.2';

$reqList = array(

    '8.2' => array(
        'php' => '8.2',
        'openssl' => true,
        'fileinfo' => true,
        'pdo' => true,
        'curl' => true,
        'mbstring' => true,
        'tokenizer' => true,
        'xml' => true,
        'ctype' => true,
        'json' => true,
        'bcmath' => true,
        'gd' => true,
        'zip' => true,
        'obs' => ''
    ),
);

$requirements = array();

// Version PHP
$requirements['php_version'] = version_compare(PHP_VERSION, $reqList[$laravelVersion]['php'], ">=");

// OpenSSL PHP Extension
$requirements['openssl_enabled'] = extension_loaded("openssl");

// PDO PHP Extension
$requirements['pdo_enabled'] = defined('PDO::ATTR_DRIVER_NAME');

// Mbstring PHP Extension
$requirements['mbstring_enabled'] = extension_loaded("mbstring");

// Curl PHP Extension
$requirements['curl_enabled'] = extension_loaded("curl");

// Tokenizer PHP Extension
$requirements['tokenizer_enabled'] = extension_loaded("tokenizer");

// XML PHP Extension
$requirements['xml_enabled'] = extension_loaded("xml");

// CTYPE PHP Extension
$requirements['ctype_enabled'] = extension_loaded("ctype");

// File PHP Extension
$requirements['fileinfo_enabled'] = extension_loaded("fileinfo");

// gd PHP Extension
$requirements['gd_enabled'] = extension_loaded("gd");

// JSON PHP Extension
$requirements['json_enabled'] = extension_loaded("json");

// BCMath
$requirements['bcmath_enabled'] = extension_loaded("bcmath");

// ZipArchive PHP Extension
$requirements['zip_enabled'] = class_exists('ZipArchive');

$allValuesAreTrue = (count(array_unique($requirements)) === 1);

$checks = array(
    array('name' => 'PHP >= ' . $reqList[$laravelVersion]['php'], 'note' => 'Running ' . PHP_VERSION, 'ok' => $requirements['php_version']),
    array('name' => 'OpenSSL',   'note' => 'PHP extension', 'ok' => $requirements['openssl_enabled']),
    array('name' => 'PDO',       'note' => 'PHP extension', 'ok' => $requirements['pdo_enabled']),
    array('name' => 'Mbstring',  'note' => 'PHP extension', 'ok' => $requirements['mbstring_enabled']),
    array('name' => 'cURL',      'note' => 'PHP extension', 'ok' => $requirements['curl_enabled']),
    array('name' => 'Tokenizer', 'note' => 'PHP extension', 'ok' => $requirements['tokenizer_enabled']),
    array('name' => 'XML',       'note' => 'PHP extension', 'ok' => $requirements['xml_enabled']),
    array('name' => 'Ctype',     'note' => 'PHP extension', 'ok' => $requirements['ctype_enabled']),
    array('name' => 'Fileinfo',  'note' => 'PHP extension', 'ok' => $requirements['fileinfo_enabled']),
    array('name' => 'GD',        'note' => 'PHP extension', 'ok' => $requirements['gd_enabled']),
    array('name' => 'JSON',      'note' => 'PHP extension', 'ok' => $requirements['json_enabled']),
    array('name' => 'BCMath',    'note' => 'PHP extension', 'ok' => $requirements['bcmath_enabled']),
    array('name' => 'ZipArchive','note' => 'PHP class',     'ok' => $requirements['zip_enabled']),
);

?>

@extends('setup.main')
@section('content')

    <p class="eyebrow">Step 1 of 4</p>
    <h1>Server requirements</h1>
    <p class="lead-text">Stocky checks that your server can run the application before anything is installed.</p>

    @if (! $allValuesAreTrue)
        <div class="alert-banner danger">
            <i class="fa fa-exclamation-triangle"></i>
            <span>Your server doesn't meet all requirements. Enable the missing extensions below, then <a href="/setup" style="color:inherit;text-decoration:underline;">re-check</a>.</span>
        </div>
    @endif

    <ul class="req-grid">
        @foreach ($checks as $check)
            <li class="req {{ $check['ok'] ? 'ok' : 'fail' }}">
                <span class="req-name">{{ $check['name'] }}<small>{{ $check['note'] }}</small></span>
                <span class="req-state"><i class="fa {{ $check['ok'] ? 'fa-check' : 'fa-times' }}"></i></span>
            </li>
        @endforeach
    </ul>

    <div class="pane-nav">
        <span class="spacer"></span>
        @if ($allValuesAreTrue)
            <a href="/setup/step-1" id="next" class="btn btn-accent">Continue <i class="fa fa-angle-right"></i></a>
        @else
            <a href="/setup" class="btn btn-ghost"><i class="fa fa-refresh"></i> Re-check requirements</a>
        @endif
    </div>

@endsection
