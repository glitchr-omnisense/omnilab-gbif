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
| `entity()` | an occurrence: `gbif.occurrence:5007681734`, `https://www.gbif.org/occurrence/5007681734` | `v1/occurrence/5007681734` |
| `occurrences()` | an `OccurrenceQuery` (below) | `v1/occurrence/search?checklistKey=...&hasCoordinate=true&occurrenceStatus=PRESENT&eventDate=&limit=&offset=` with the place and the filters |

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

## Occurrences

An occurrence is a record of an organism at a place and a time - observed (most, through
iNaturalist and the bird atlases), collected (a museum's specimen), sampled, sequenced. GBIF holds
3,981,411,127 (2026-10-15). `occurrences()` answers them in the core's fourth shape: a `Page` of
entities, each with its time and its place.

| `OccurrenceQuery` | GBIF |
|---|---|
| `place`: a `BBox` | `decimalLatitude=south,north&decimalLongitude=west,east`; a box across the antimeridian, which GBIF's ranges do not take, as two `geometry` polygons (GBIF ORs them) |
| `place`: a `Point` and `radius` | `geoDistance=lat,lon,Rkm`; `radius_km` (10) when the query gives none |
| `place`: none | the world |
| `from`, `to` | `eventDate=from,to` in whole days (GBIF takes no time: a time stands for its day), `*` for an open end - always sent, so a record without a date is never answered |
| `OccurrenceQuery::TAXON` | a key (`4CGXP`, `gbif:4CGXP`, an `Identifier`): `taxonKey`, which takes the subordinate taxa and the synonyms (the lion's subspecies); a legacy number (`5219404`): `taxonKey` without the checklist, read in the frozen Backbone (GBIF finds nothing under a legacy number in the COL XR); anything else, a scientific name: `scientificName`, matched in the checklist |
| `OccurrenceQuery::COUNTRY` | ISO 3166-1 alpha-2, or a list: `country` |
| `OccurrenceQuery::KIND` | the basis of record, or a list: `human_observation`, `preserved_specimen`, `machine_observation`, `material_sample`... (`basisOfRecord`) |
| `limit`, `cursor` | `limit` (300 at most), `offset`; the last page GBIF answers ends 100,000 deep, a cursor past it is refused |
| `sort` | refused: GBIF answers in its own order |

Always asked: `hasCoordinate=true` (a place), `occurrenceStatus=PRESENT` (not a recorded absence),
the checklist. The total is GBIF's `count`. A filter of another domain is refused before any call.

An occurrence holds: `id` (`gbif.occurrence:5007681734`), `label` (the name, the country, the day:
`Panthera leo melanochaita - South Africa, 2025-01-03`), `domain` taxonomy, `kind` `occurrence`,
`time` (the event date as GBIF interprets it: its start for an interval, the first day of a year or
a month given alone, at the offset of its `eventTime` when the date has none, else UTC), `place`
(its coordinates, and its elevation when given), `url` (its GBIF page), `image` (its first still
image), `related` (`taxon`: the checklist's key for what it was identified as and the legacy
number; `accepted` when that is a synonym), and these properties:

| Key | GBIF | Value |
|---|---|---|
| `taxon_name`, `scientific_name`, `taxon_rank` | the checklist's usage: canonical name, with its authority, rank | text |
| `domain`, `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | the checklist's classification | text |
| `accepted_name` | the accepted usage, for a synonym | text |
| `basis_of_record` | basisOfRecord: `human observation`, `preserved specimen`... | text |
| `event_date` | eventDate as GBIF interprets it: its precision kept (`2025-01-03T17:38`, `2023-02-22`, an interval) | text |
| `individual_count`, `sex`, `life_stage` | individualCount, sex, lifeStage | number, text |
| `country`, `country_code`, `state_province`, `locality` | country, countryCode, stateProvince, locality (or the verbatim one) | text |
| `coordinate_uncertainty`, `depth` | coordinateUncertaintyInMeters, depth | Quantity, m |
| `recorded_by`, `identified_by` | recordedBy, identifiedBy | text |
| `dataset`, `institution_code`, `occurrence_id`, `license` | datasetName, institutionCode, the publisher's id, the record's licence | text |
| `information_withheld` | informationWithheld: a sensitive species' blurred place, for one | text |

A page holds what the record holds: `entity($occurrence->id)` gives the same, in one call.

## What GBIF does not give here

- No Wikidata item, no iNaturalist taxon: GBIF keeps neither as a checklist (Wikidata finds the
  taxon by its GBIF key, P14607, or its legacy number, P846; its item gives the iNaturalist id).
- Not read: the vernacular names in other languages (but in a search), the descriptions,
  distributions, species profiles, media and references of the checklist, its children and
  synonyms (`v1/species/{key}/children`, `synonyms`), the other checklists' related usages but the
  ENA's, GBIF's maps.

## The Collector

*Panthera leo* from GBIF into NCBI Taxonomy and Wikidata, answered on 2026-10-15: see the core's
`docs/merging.md`. GBIF gives the NCBI id (9689) and the legacy number; Wikidata is offered the new
key first (no item carries `P14607=4CGXP` yet), then the number (`P846=5219404`: Q140).

## Verified, and not

Occurrences, against the real service on 2026-10-15: the lions (4CGXP) placed and dated within
100 km of Skukuza (-24.99, 31.59) in 2025, 412 in two pages of 300 (35 s the first time, GBIF's
`geoDistance` slow; 3 s asked again), every one in 2025 and with its place; the first, an iNaturalist
observation of *Panthera leo melanochaita* on 2025-01-03 at 17:38 (+02:00), its place blurred to
30,090 m "to protect threatened taxon"; 513 in the box of the Kruger National Park by the name
*Panthera leo*, 757 in South Africa; 16,675 in all, by the key and by the legacy number alike;
317 preserved specimens, dated by the day; the 262 occurrences of January 2025 in a box across the
antimeridian (170° to -170°, 20° S to 15° S), 221 east and 41 west of it, as the two halves count;
seven within 10 km of Strasbourg from 2026-10-01 to 10-07 (a snail, a lizard, the Asian hornet, a
mute swan...); an occurrence by its key (the same properties as in a page) and an unknown key
(404); GBIF's limits: 300 a page (it answers 300 for 1000), offset and limit within 100,000
(99,700 + 300 answered, 99,990 + 20 refused), no time in `eventDate` (400), no wrapping range of
longitudes (400), no sort (a `sort` changes nothing).

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
