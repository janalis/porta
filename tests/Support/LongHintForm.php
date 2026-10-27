<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Widget\Settings\SettingsFormWidget;

/** A settings form of three rows, the middle one's hint far too long to sit beside its field in a narrow pane. */
final class LongHintForm
{
    public static function create(): SettingsFormWidget
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([
            new SettingsRow(key: 'plain', label: 'Plain', kind: SettingsRowKind::Bool, value: true, restart: false, default: true),
            new SettingsRow(key: 'long', label: 'Long', kind: SettingsRowKind::Text, value: '', restart: false, hint: 'a hint far too long to fit beside the field in a narrow pane'),
            new SettingsRow(key: 'changed', label: 'Changed', kind: SettingsRowKind::Bool, value: false, restart: false, default: true),
        ]);

        return $widget;
    }
}
