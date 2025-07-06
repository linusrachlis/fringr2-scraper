# Toronto Fringe 2025 performance data scraper

Scraper for show info and performance times on the [Toronto Fringe Theatre
Festival 2025 website](https://fringetoronto.com/fringe/shows). Used for
https://github.com/linusrachlis/fringr2-fe.

## Running

Have PHP >=7.

```bash
php scrape.php # Dumps everything to stdout (operational info and JSON output)
php scrape.php out.json # Gives operation info to stdout and JSON output to a file
```

Copy the contents of `out.json` and use it to replace the value of `showsData`
in `fringr2-fe/src/data/shows.ts`.

## Generating URLs list

1. Visit https://fringetoronto.com/fringe/shows.
2. Use the "More info" buttons on this page to generate a list of all show
   URLs. Open the Javascript console and run something like:
    ```js
    console.log(Array.from(document.querySelectorAll(".more-link a")).map(e => e.href).join('\n'))
    ```
3. Copy the output and use it to replace the contents of `play_urls.txt`.
