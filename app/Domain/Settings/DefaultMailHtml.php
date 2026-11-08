<?php

namespace App\Domain\Settings;

final class DefaultMailHtml
{
    public static function make(string $heading, string $innerHtml, string $buttonLabel = '', string $buttonUrl = ''): string
    {
        $button = '';
        if ($buttonLabel !== '' && $buttonUrl !== '') {
            $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;"><tr><td style="border-radius:8px;background:#6d28d9;">'
                .'<a href="'.$buttonUrl.'" style="display:inline-block;padding:12px 20px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;font-family:Arial,sans-serif;">'
                .$buttonLabel.'</a></td></tr></table>';
        }

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f7f8fa;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f8fa;padding:24px 0;">'
            .'<tr><td align="center">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">'
            .'<tr><td style="padding:20px 32px;background:#6d28d9;color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:700;">{{shop_name}}</td></tr>'
            .'<tr><td style="padding:32px;font-family:Arial,sans-serif;color:#171717;font-size:15px;line-height:1.6;">'
            .'<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#171717;">'.$heading.'</h1>'
            .$innerHtml
            .$button
            .'</td></tr>'
            .'<tr><td style="padding:16px 32px;background:#fafafa;color:#737373;font-family:Arial,sans-serif;font-size:12px;">{{shop_name}}</td></tr>'
            .'</table></td></tr></table></body></html>';
    }
}
