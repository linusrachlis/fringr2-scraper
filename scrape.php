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
    $runtime_text = $crawler->filter('.show-info')->first()->filter('.column.right dd')->text();
    $runtime_minutes = preg_replace('/^(\d+).*$/', '$1', $runtime_text);

    $location_address_node = $crawler->filter('address.venue-address');

    $location_name = $location_address_node->previousAll()->text();
    $location_name = preg_replace('@^\s*\d+\s*:\s*(.+)$@', '$1', $location_name);

    $location_address_html = $location_address_node->filter('p:first-child')->html();
    $location_address = preg_replace('@<br( /)?>@', ', ', $location_address_html);
    $location_address = strip_tags($location_address);

    $flags = [
        '.warning-icon-assisted-hearing-devices' => 'assisted-hearing',
        '.warning-icon-audio-description' => 'audio-description',
        '.warning-icon-relaxed-performance' => 'relaxed',
        '.warning-icon-sign-language' => 'asl',
        '.warning-icon-tad-seating' => 'tad',
        '.warning-icon-touch-book' => 'touch-book',
        '.warning-icon-touch-tour' => 'touch-tour',
    ];
    $all_flags_selector = implode(',', array_keys($flags));

    $perfs = [];
    $perf_counter = 0;

    $crawler->filter('.performances table tbody tr')->each(
        function (Crawler $node) use (
            &$perfs,
            &$perf_counter,
            $runtime_minutes,
            &$flags,
            $all_flags_selector
        ) {
            $cells = $node->filter('td');
            $date = $cells->eq(1)->text();

            $perf_flag_symbols = [];
            $cells->eq(3)->filter($all_flags_selector)->each(
                function (Crawler $node) use (&$flags, &$perf_flag_symbols)
                {
                    $lookup = '.' . $node->attr('class');
                    if (array_key_exists($lookup, $flags)) {
                        $perf_flag_symbols[] = $flags[$lookup];
                    }
                }
            );

            // Preview symbol is presented differently on Fringe site
            if ($cells->eq(0)->filter('.icon-preview')->count() == 1) {
                $perf_flag_symbols[] = 'preview';
            }

            $time = preg_replace('/^.*?(\d+:\d+[ap]m).*?$/', '$1', $cells->eq(2)->text());
            $start_time = new DateTime("$date, $time");
            $end_time = (new DateTime("$date, $time"))->add(new DateInterval("PT{$runtime_minutes}M"));

            $perfs[] = [
                'id' => $perf_counter++,
                'flags' => $perf_flag_symbols,
                'start' => $start_time->format('c'),
                'end' => $end_time->format('c'),
            ];
        }
    );

    return [
        'title' => trim($title),
        'url' => $show_url,
        'venue' => trim($location_name),
        'address' => trim($location_address),
        'id' => $show_index,
        'perfs' => $perfs,
    ];
}
