# omnilab/gbif

GBIF's taxonomy for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab): a taxon by its
key, by its legacy GBIF Backbone number, by its NCBI Taxonomy id or by a scientific name GBIF
matches; its name, authority, rank, taxonomic status, IUCN Red List category, common name, place
of publication and classification; its legacy number and its NCBI id as cross-references for the
Collector to follow, its parent (or, for a synonym, its accepted taxon) as a related thing; a search
of GBIF's names. And the occurrences of a place and a period - the records of an organism found
there then, observed, collected or sampled -, each an entity with its time and its place, its taxon
related. Through the GBIF API, no key.

Since June 2026 GBIF's taxonomy is the **Catalogue of Life eXtended Release**, whose keys are short
and alphanumeric (`4CGXP` for the lion); the GBIF Backbone it replaces, frozen since 2023, numbered
its taxa (`5219404`, the same lion), and most of the world still cites those numbers (Wikidata's
P846, NCBI's links). The source answers with the new key and gives the legacy number beside it; the
core's `gbif` scheme reads both.

```php
$gbif = (new GbifSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$lion = $gbif->entity('4CGXP');                  // or '5219404', 'gbif:4CGXP', 'Panthera leo', 'Felis leo', 'taxonomy:9689'
$lion->label;                                    // Panthera leo
$lion->value('iucn_status');                     // Vulnerable (VU)
$lion->value('family');                          // Felidae
$lion->identifiers()->keys();                    // gbif:4CGXP, gbif:5219404, ncbi.taxon:9689
$lion->related('parent')->keys();                // gbif:6DBT (Panthera)
$gbif->search(new Query('Panthera leo', limit: 10));

// The lions observed within 100 km of Skukuza (Kruger National Park) in 2025.
$page = $gbif->occurrences(new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'),
    new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => '4CGXP'], radius: 100, limit: 300));
$page->total;                                    // 412
$lion = $page->first();
$lion->label;                                    // Panthera leo melanochaita - South Africa, 2025-01-03
$lion->time;                                     // 2025-01-03 17:38 +02:00
$lion->related('taxon')->keys();                 // gbif:7KGW9, gbif:5219411: the subspecies, related
$gbif->occurrences($query->with(['cursor' => $page->next]));   // the next 300
```

```yaml
omnilab:
    sources:
        gbif:
            factory: gbif
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent, as GBIF asks: whom it writes to before it slows a caller down
                ncbi: true                     # read the NCBI id (one call more)
                language: 'eng'                # the common names a search result keeps (ISO 639-2)
                radius_km: 10                  # the circle around a Point, for occurrences(), when a query gives none
```

With NCBI and Wikidata configured too, the `Collector` follows a species from one to the others:
`$collector->collect('gbif', 'Panthera leo')` reaches NCBI Taxonomy by the NCBI id GBIF keeps for
it, and Wikidata by its GBIF key (P14607) or, for an item that has none yet, its legacy number
(P846).

## Terms of use

GBIF's API is free and needs no key; GBIF asks a client to name itself in its User-Agent (a URL or
an address: set `mailto`), publishes no fixed rate and answers 429 to a caller it slows down - its
search APIs first, when its servers are loaded. The checklists are under the licences their
publishers chose (the Catalogue of Life's is CC BY 4.0): cite GBIF and the Catalogue of Life where
you show them. Each occurrence keeps its own dataset's licence (`license`, often CC BY-NC) and its
publisher: show them with it. The occurrence search answers 300 a page and stops 100,000 deep; for
more, GBIF's downloads (an account, a DOI to cite) are the way, out of this package.

See [docs/](docs/index.md) for what is read, how it maps, and what GBIF does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by GBIF and the institutions that publish through it. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
