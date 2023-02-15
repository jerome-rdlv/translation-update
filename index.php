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
     * All options are passed to `wp i18n make-pot`
     *
     * <source_path>
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
                    ? sprintf($value ? '--no-%s' : '--%s', $arg)
                    : sprintf('--%s="%s"', $arg, addslashes($value));
            }, array_keys($assoc), $assoc)),
            implode(' ', $args),
        );

        $output = WP_CLI::runcommand($command, ['return' => 'all']);

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
}
