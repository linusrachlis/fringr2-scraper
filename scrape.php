<?php

/**
 * Pass output file as first position argument to write JSON to that file.
 * Otherwise JSON will go to stdout along with operation info.
 */

use Symfony\Component\DomCrawler\Crawler;

require 'vendor/autoload.php';
date_default_timezone_set('America/Toronto');

$cache_dir = __DIR__ . '/fetch_cache/';
if (!is_dir($cache_dir) && !mkdir($cache_dir)) {
    echo "Failed to create cache directory: $cache_dir\n";
    exit(1);
}
if (!is_writable($cache_dir) && !chmod($cache_dir, 0777)) {
    echo "Failed to set cache directory writable: $cache_dir\n";
    exit(1);
}

$urls_file = __DIR__ . '/play_urls.txt';
$show_urls = file($urls_file, FILE_IGNORE_NEW_LINES);
if (!is_array($show_urls)) {
    echo "Failed to read $urls_file\n";
    exit(1);
}

$scrapes = [];
foreach ($show_urls as $show_index => $show_url) {
    try {
        echo "--> $show_url\n";
        $scrapes[] = scrape($show_url, $show_index);
    } catch (Exception $e) {
        echo "Caught exception for $show_index: $show_url\n";
        throw $e;
    }
}

$output = json_encode($scrapes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($argc > 1) {
    $outfile = $argv[1];
    file_put_contents($outfile, $output);
} else {
    echo $output;
}


function scrape($show_url, $show_index) {
    global $cache_dir;

    $cache_key = sha1($show_url);
    $cache_file = $cache_dir . $cache_key;
    if (is_file($cache_file)) {
        echo "using cache\n";
        $fetched_html = file_get_contents($cache_file);
    } else {
        echo "fetching page\n";
        $fetched_html = file_get_contents($show_url);
        file_put_contents($cache_file, $fetched_html);
    }
    $crawler = new Crawler($fetched_html);

    $title = trim($crawler->filter('.page-title')->text());
    $runtime_text_container = $crawler->filter('.show-info')->first()->filter('.column.right dd');
    if ($runtime_text_container->count() > 0) {
        $runtime_text = $runtime_text_container->text();
        $runtime_minutes = preg_replace('/^\s*(\d+).*$/s', '$1', $runtime_text);
    } else {
        $runtime_minutes = null; // Some shows have no runtime defined (e.g. they just go until "late")
    }


    $location_name_node = $crawler->filter('.venue-info:first-child h3');
    assert(count($location_name_node) == 1, 'Expected exactly one location name node');
    $location_name = $location_name_node->text();
    $location_name = trim(preg_replace('@^\s*\d+\s*:\s*(.+)\s*$@s', '$1', $location_name));

    $location_address_node = $crawler->filter('.venue-info:first-child address');
    $location_address_node_count = count($location_address_node);
    assert($location_address_node_count < 2, 'Expected at most one location address node');

    if ($location_address_node_count == 1) {
        $location_address_html = $location_address_node->filter('p:first-child')->html();
        $location_address = preg_replace('@<br( /)?>@', ', ', $location_address_html);
        $location_address = trim(strip_tags($location_address));
    } else {
        $location_address = null;
    }


    $perfs = [];
    $perf_counter = 1;

    $perf_nodes = $crawler->filter('.performances table tbody tr');
    assert($perf_nodes->count() > 0, 'Expected at least one performance node');
    $perf_nodes->each(
        function (Crawler $node) use (
            &$perfs,
            &$perf_counter,
            $runtime_minutes,
            &$flags,
        ) {
            $cells = $node->filter('td');
            if ($cells->count() < 5) {
                // Some rows have no cells like when a perf is cancelled
                return;
            }
            $date = $cells->eq(1)->text();

            // Build up the list of flags for this performance
            $perf_flag_symbols = [];

            // Most flags are presented as icons in the 4th table cell.
            $cells->eq(3)->children()->each(
                function (Crawler $node) use (&$perf_flag_symbols)
                {
                    $flags = [
                        'warning-icon-assisted-hearing-devices' => 'assisted-hearing',
                        'warning-icon-audio-description' => 'audio-description',
                        'warning-icon-closed-captioning' => 'closed-captioning',
                        'warning-icon-relaxed-performance' => 'relaxed',
                        'warning-icon-sign-language' => 'asl',
                        'warning-icon-tad-seating' => 'tad',
                        'warning-icon-touch-book' => 'touch-book',
                        'warning-icon-touch-tour' => 'touch-tour',
                    ];
                    $lookup = $node->attr('class');
                    assert(array_key_exists($lookup, $flags), "Unknown flag from 4th column: $lookup");
                    $perf_flag_symbols[] = $flags[$lookup];
                }
            );

            // Some other icons are in the first table cell, and we want to
            // make flags out of them, too.
            $cells->eq(0)->children()->each(
                function (Crawler $node) use (&$perf_flag_symbols)
                {
                    $flags = [
                        'icon-preview' => 'preview',
                        'icon-pwyc' => 'pwyc',
                        'icon-discount' => 'daily-discount',
                    ];
                    $classes = explode(' ', $node->attr('class'));
                    foreach ($classes as $lookup) {
                        // Ignore 'icon' class
                        if ($lookup == 'icon') continue;

                        assert(array_key_exists($lookup, $flags), "Unknown flag from 1st column: $lookup");
                        $perf_flag_symbols[] = $flags[$lookup];
                        break;
                    }
                }
            );

            $time = preg_replace('/^.*?(\d+:\d+[ap]m).*?$/', '$1', $cells->eq(2)->text());
            $start_time = new DateTime("$date, $time");

            $new_perf = [
                'id' => $perf_counter++,
                'flags' => $perf_flag_symbols,
                'start' => $start_time->format('c'),
            ];

            if (isset($runtime_minutes)) {
                $end_time = (new DateTime("$date, $time"))->add(new DateInterval("PT{$runtime_minutes}M"));
                $new_perf['end'] = $end_time->format('c');
            }

            $perfs[] = $new_perf;
        }
    );

    return [
        'title' => $title,
        'url' => $show_url,
        'venue' => $location_name,
        'address' => $location_address,
        'id' => $show_index + 1,
        'perfsData' => $perfs,
    ];
}
