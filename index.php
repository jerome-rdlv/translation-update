<?php

/**
 * Plugin Name: Translation update
 * Plugin URI: https://rue-de-la-vieille.fr
 * Author: Jérôme Mulsant
 * Author URI: https://rue-de-la-vieille.fr
 * Description: A wp-cli command to extract texts from a plugin or theme, and update POT, PO and MO files.
 * Version: GIT
 */

namespace Rdlv\WordPress\Translation;

use WP_CLI;

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
    /** @noinspection PhpParamsInspection */
    WP_CLI::add_command('translation', new TranslationCommand());
}

class TranslationCommand
{
    /**
     * Extract translations from source and update corresponding POT, PO and MO files.
     * Except from [--plugin] and [--theme], all options are passed to `wp i18n make-pot`
     *
     * [--plugin=<name>]
     * : Plugin strings will be extracted, resulting POT file will be created in global languages dir
     *
     * [--theme=<name>]
     * : Theme strings will be extracted, resulting POT file will be created in global languages dir
     *
     * [<source>]
     * : The source directory to extract from
     *
     * [<destination>]
     * : The path of the generated POT file
     *
     * @param $args
     * @param $assoc
     * @return void
     */
    public function update($args, $assoc)
    {
        $headers = [
            'Project-Id-Version' => null,
            'Report-Msgid-Bugs-To' => null,
            'Language-Team' => null,
            'POT-Creation-Date' => 'GIT',
        ];

        if (isset($assoc['plugin'])) {
            if (!empty($args)) {
                WP_CLI::error('You can’t use <source> argument and --plugin option at the same time.');
            }
            $plugin = $assoc['plugin'];
            unset($assoc['plugin']);
            $args[0] = sprintf('%s/%s', WP_PLUGIN_DIR, $plugin);
            $args[1] = sprintf('%s/plugins/%s.pot', WP_LANG_DIR, $this->getPluginData($plugin)['TextDomain'] ?? $plugin);
        } elseif (isset($assoc['theme'])) {
            if (!empty($args)) {
                WP_CLI::error('You can’t use <source> argument and --theme option at the same time.');
            }
            $theme = wp_get_theme($assoc['theme']);
            unset($assoc['theme']);
            $args[0] = $theme->get_stylesheet_directory();
            $args[1] = sprintf('%s/themes/%s.pot', WP_LANG_DIR, $theme->get('TextDomain') ?? $theme->get_stylesheet());
        }

        $assoc = array_merge(
            [
                'headers' => json_encode($headers),
                'skip-js' => true,
            ],
            $assoc
        );

        $command = sprintf(
            'i18n make-pot --debug %s %s',
            implode(' ', array_map(function ($arg, $value) {
                return is_bool($value)
                    ? sprintf($value ? '--%s' : '--no-%s', $arg)
                    : sprintf('--%s="%s"', $arg, addslashes($value));
            }, array_keys($assoc), $assoc)),
            implode(' ', $args),
        );

        $output = WP_CLI::runcommand($command, ['return' => 'all', 'exit_error' => false]);

        echo preg_replace('/^Debug \([^\)]+\): .*$\n?/m', '', $output->stderr);
        echo $output->stdout . "\n";

        if ($output->return_code !== 0) {
            return;
        }

        if (!preg_match('/^Debug \(make-pot\): Destination: ([^\s]+)/m', $output->stderr, $matches)) {
            return;
        }

        $pot = $matches[1];
        $this->dropEmptyHeadersFromPot($pot, $headers);
        WP_CLI::runcommand(sprintf('i18n update-po %s', $pot));
        WP_CLI::runcommand(sprintf('i18n make-mo %s', dirname($pot)));
    }

    private function dropEmptyHeadersFromPot($pot, $headers)
    {
        $header = true;
        file_put_contents(
            $pot,
            implode('', array_filter(file($pot), function ($line) use ($headers, &$header) {
                if ($line === "\n") {
                    $header = false;
                    return true;
                }
                if (!$header) {
                    return true;
                }
                if (!preg_match('/^"([^:]*): /', $line, $m)) {
                    return true;
                }
                if (!array_key_exists($m[1], $headers)) {
                    return true;
                }
                return $headers[$m[1]] !== null;
            }))
        );
    }

    private function getPluginData(string $name): ?array
    {
        foreach (get_plugins() as $path => $data) {
            if (dirname($path) === $name) {
                return $data;
            }
        }
        return null;
    }
}
