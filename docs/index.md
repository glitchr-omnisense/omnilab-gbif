# omnilab/gbif

## Installation

```sh
composer require omnilab/gbif
```

```php
use Omnilab\Gbif\GbifSourceFactory;

$gbif = (new GbifSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, asked by GBIF
    'ncbi' => true,                    // optional
]);
```

With the Symfony bundle, the factory is registered as `gbif` (see the core's docs).

## Which API, which taxonomy

The GBIF API, `https://api.gbif.org/`. Since 23 June 2026 GBIF's taxonomy is the Catalogue of Life
eXtended Release (COL XR: the dataset `7ddf754f-d193-4cc9-b351-99906754a03b` in GBIF, published
2026-06-19, CC BY 4.0), whose keys are alphanumeric: one to six of the digits 2 to 9 and the
consonants (`4CGXP` the lion, `6DBT` *Panthera*, `N` Animalia). The GBIF Backbone Taxonomy it
replaces (`d7dddbf4-...`), frozen since 2023, numbered its taxa (`5219404` the lion). On
2026-10-15 GBIF's API still answers a call that names no checklist with the legacy Backbone
(`v1/species/match?name=Panthera leo` gives 5219404): every call here names the COL XR (the
`checklist` option). GBIF's way from a legacy number to the new key is its own match,
`scientificNameID=gbif:5219404`; its website sends a legacy page (`gbif.org/species/5219404`) to
the new one (`gbif.org/taxon/4CGXP`).

The calls of a taxon:

1. `v2/species/match?checklistKey=...` by `usageKey` (a key), `scientificNameID=gbif:` (a legacy
   number) or `scientificName` (a name): the usage (name, authorship, rank, status, code), its
   classification with each rank's key, its IUCN Red List category (`additionalStatus`);
2. `v1/species?datasetKey=...&sourceId=4CGXP`: the checklist's record - its common name, its place
   of publication, and its legacy number (`nubKey`);
3. `v1/species/5219404/related?datasetKey=6b6b2923-...`: the taxa related to the legacy number in
   the European Nucleotide Archive's taxonomy, which is NCBI Taxonomy's: their `taxonID` is the
   NCBI id (`9689`). Left out with `ncbi: false`.

An NCBI id is read the other way first: `v1/species?datasetKey=6b6b2923-...&sourceId=9689` gives
the legacy number, then as above.

## Calls

| Method | Reads | GBIF |
|---|---|---|
| `entity()` | a key (`4CGXP`, `gbif:4CGXP`, `https://www.gbif.org/taxon/4CGXP`) | match by `usageKey`, record, related |
| | a legacy number (`5219404`, `gbif:5219404`, `https://www.gbif.org/species/5219404`) | match by `scientificNameID=gbif:5219404`, record, related |
| | an NCBI id (an `Identifier` of `ncbi.taxon`, `taxonomy:9689`) | the ENA record, then as a legacy number |
| | a scientific name (`Panthera leo`; a synonym, `Felis leo`, gives its accepted taxon, the synonym among its synonyms) | match by `scientificName`, record, related |
| `search()` | `Query::$text`: GBIF's search of the checklist's names, common names included; a domain other than taxonomy answers nothing | `v1/species/search?datasetKey=...&q=&limit=&offset=` |

A bare string of a key's shape is a key, digits a legacy number; anything else a name. A match is
kept when GBIF says `EXACT`, `FUZZY` or `VARIANT`; a name it matches at a higher rank only is
`null` (`Panthera xyzzz` matches Animalia), as is a common name (`lion`: no scientific name) and an
unknown key. An identifier of another scheme is refused before any call.

## What an entity holds

`id` (its key), `label` (its canonical name), `domain` taxonomy, `kind` (its rank, lower case),
`synonyms` (its common name; the synonym it was matched by), `url` (`https://www.gbif.org/taxon/...`),
and:

| Key | GBIF | Value |
|---|---|---|
| `taxon_name` | usage.canonicalName (Wikidata's and NCBI's key) | text |
| `taxon_authority` | usage.authorship | text |
| `scientific_name` | usage.name, the name and its authority | text |
| `taxon_rank` | usage.rank | text |
| `taxonomic_status` | usage.status: `accepted`, `synonym`, `provisionally accepted`... | text |
| `nomenclatural_code` | usage.code: `zoological`, `botanical`... | text |
| `common_name` | the record's vernacularName | text |
| `published_in` | the record's publishedIn | text |
| `iucn_status` | the IUCN's additionalStatus: `Vulnerable (VU)` | text |
| `domain`, `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | the classification at those ranks (NCBI's keys) | text |
| `lineage` | the whole classification from the root down, every rank | text |
| `accepted_name` | a synonym's accepted usage | text |

`identifiers`: its legacy GBIF Backbone number and its NCBI Taxonomy id. `related`: `parent` (the
taxon above it, by its key), or `accepted` for a synonym (whose classification is its accepted
taxon's). A search result holds the name, authority, rank, status, the main ranks, its English
common names (the `language` option) and its legacy number: `entity()` reads the rest.

## What GBIF does not give here

- No Wikidata item, no iNaturalist taxon: GBIF keeps neither as a checklist (Wikidata finds the
  taxon by its GBIF key, P14607, or its legacy number, P846; its item gives the iNaturalist id).
- **Occurrences are left for later.** An occurrence is an observation at its own place and time;
  the core's `Series` is one variable at one place over time, and `Query` has no place: neither
  holds "the occurrences of a taxon in a box" without bending it. They would take either a new
  capability (`Capability::OCCURRENCES`, entities of kind `occurrence` with `time` and `place`, a
  query with a `BBox`) or the `Product` shape - a decision for the core, not taken here. GBIF's
  occurrence search takes the new keys already (`occurrence/search?taxonKey=4CGXP&checklistKey=...`:
  20 352 lions on 2026-10-15, 20 262 by the legacy number).
- Not read: the vernacular names in other languages (but in a search), the descriptions,
  distributions, species profiles, media and references of the checklist, its children and
  synonyms (`v1/species/{key}/children`, `synonyms`), the other checklists' related usages but the
  ENA's, GBIF's maps.

## The Collector

*Panthera leo* from GBIF into NCBI Taxonomy and Wikidata, answered on 2026-10-15: see the core's
`docs/merging.md`. GBIF gives the NCBI id (9689) and the legacy number; Wikidata is offered the new
key first (no item carries `P14607=4CGXP` yet), then the number (`P846=5219404`: Q140).

## Verified, and not

Against the real service on 2026-10-15, without a key: the lion by its key, its legacy number, its
pages, its scientific name, its synonym *Felis leo* (matched to `CFSCR`, accepted `4CGXP`) and its
NCBI id; its authority, status, code, IUCN category (Vulnerable), common name ("African Lion"),
publication, classification of 15 ranks, legacy number and NCBI id; a synonym by its key (`5K5L6`,
*Panthera leo kamptzi*, accepted *Panthera leo leo*, no NCBI id in the ENA taxonomy); "lion", an
unknown name matched at a higher rank only, an unknown key; a search for "Panthera leo" (17 taxa,
two pages of three); the legacy Backbone answering a call that names no checklist; the occurrence
search by the new key. The fixtures in `Tests/Fixtures/` are its answers.

Not verified: GBIF's 429 (none met: the handling is the core's); a taxon whose new key is digits
alone (the core reads such a key as a legacy number: none was met, the keys seen all hold a
consonant); a match GBIF calls `VARIANT`.
