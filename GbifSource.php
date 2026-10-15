<?php

namespace Omnilab\Gbif;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GBIF's taxonomy, through its API (api.gbif.org): a taxon by its key, by
 * its legacy GBIF Backbone number, by its NCBI Taxonomy id or by a
 * scientific name GBIF matches; its name, authorship, rank, status, IUCN
 * Red List category, common name, place of publication and classification;
 * its legacy key and its NCBI id as cross-references, its parent (and, for
 * a synonym, the accepted taxon) as related things; and a search. And the
 * occurrences of a place and a period - the records of an organism found
 * there then, observed or collected -, each an entity with its time and
 * its place, its taxon related (occurrences(), entity() by its key).
 *
 * Since June 2026 GBIF's taxonomy is the Catalogue of Life eXtended Release
 * (checklist 7ddf754f-d193-4cc9-b351-99906754a03b), its keys alphanumeric
 * (4CGXP, Panthera leo); the GBIF Backbone it replaces, frozen since 2023,
 * kept numbers (5219404, the same lion), which most of the world - Wikidata's
 * P846, NCBI's links - still cites. The source answers with the new key and
 * gives the legacy one beside it; GBIF's API still takes both, but a call
 * without a checklist key answers with the legacy Backbone: every call here
 * names the checklist.
 *
 * No key. A taxon costs three calls: GBIF's name match (the usage, its
 * classification, its IUCN status), the checklist's record (its common
 * name, its publication, its legacy number), the records related to the
 * legacy one in the European Nucleotide Archive's taxonomy (its NCBI id).
 * An occurrence, or a page of them, one.
 */
final class GbifSource extends HttpSource
{
    public const BASE_URI = 'https://api.gbif.org/';

    /** The Catalogue of Life eXtended Release in GBIF: its taxonomy since June 2026. */
    public const CHECKLIST = '7ddf754f-d193-4cc9-b351-99906754a03b';

    /** The European Nucleotide Archive's taxonomy in GBIF: NCBI Taxonomy's ids. */
    public const ENA = '6b6b2923-0a10-4708-b170-5b7c611aceef';

    /** The most taxa one search page is asked for (GBIF stops at 1000). */
    public const MAX_PER_PAGE = 1000;

    /** The most occurrences one page holds (GBIF answers 300 for more). */
    public const MAX_OCCURRENCES_PER_PAGE = 300;

    /** How deep the occurrence search pages: offset and limit together (GBIF's 400 beyond; its downloads go further). */
    public const MAX_OCCURRENCE_DEPTH = 100000;

    /** The ranks given each a property, from the root down. */
    private const RANKS = ['domain', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus'];

    /** A key of the Catalogue of Life: the digits 2 to 9 and the consonants, upper case. */
    private const KEY = '~^[2-9BCDFGHJKLMNPQRSTVWXYZ]{1,6}$~';

    /**
     * @param string $checklist  the GBIF checklist to answer from (the Catalogue of Life eXtended Release)
     * @param bool   $ncbi       whether the NCBI id is read (one call more)
     * @param string $language   the language of the common name, ISO 639-2 ("eng", "fra"), for a search's results
     * @param float  $radius     the radius around a Point, in kilometres, for occurrences() when the query gives none
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $checklist = self::CHECKLIST,
        private readonly bool $ncbi = true,
        private readonly string $language = 'eng',
        private readonly float $radius = 10.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'gbif';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::OCCURRENCES, Capability::AREA, Capability::CROSS_REFERENCES];
    }

    /**
     * A taxon by its key (4CGXP, gbif:4CGXP, its page), its legacy number
     * (5219404, its page gbif.org/species/), its NCBI id (an Identifier of
     * ncbi.taxon, taxonomy:9689) or a scientific name ("Panthera leo";
     * a synonym gives the accepted taxon). A common name is no scientific
     * name: GBIF matches none ("lion"). An occurrence by its key
     * (gbif.occurrence:5938104699, its page): one call.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::GBIF, Scheme::NCBI_TAXON, Scheme::GBIF_OCCURRENCE];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('gbif', (string) $id);
        } else {
            $text = trim($id);
            $identifier = preg_match(self::KEY, $text) || ctype_digit($text) ? Identifier::tryOf(Scheme::GBIF, $text) : Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('gbif', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }
        if (Scheme::GBIF_OCCURRENCE === $identifier->scheme) {
            $record = $this->getJson('v1/occurrence/'.$identifier->value);

            return null === $record || !isset($record['key']) ? null : $this->toOccurrence($record);
        }
        if (Scheme::NCBI_TAXON === $identifier->scheme) {
            $legacy = $this->getJson('v1/species', ['datasetKey' => self::ENA, 'sourceId' => $identifier->value])['results'][0]['nubKey'] ?? null;

            return null === $legacy ? null : $this->taxon(['scientificNameID' => 'gbif:'.$legacy]);
        }

        return ctype_digit($identifier->value)
            ? $this->taxon(['scientificNameID' => 'gbif:'.$identifier->value])
            : $this->taxon(['usageKey' => $identifier->value]);
    }

    /**
     * GBIF's search of the checklist: its names, common names included,
     * matching the text ("Panthera", "lion"), page by offset. A domain other
     * than taxonomy answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::TAXONOMY !== $query->domain) || '' === $text) {
            return new Page();
        }
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $offset = max(0, (int) $query->cursor);
        $data = $this->getJson('v1/species/search', ['datasetKey' => $this->checklist, 'q' => $text, 'limit' => $limit, 'offset' => $offset ?: null]) ?? [];
        $entities = [];
        foreach ($data['results'] ?? [] as $record) {
            if (\is_array($record) && isset($record['taxonID'])) {
                $entities[] = $this->fromSearch($record);
            }
        }
        $count = \count($data['results'] ?? []);

        return new Page($entities, false === ($data['endOfRecords'] ?? true) && $count ? (string) ($offset + $count) : null, isset($data['count']) ? (int) $data['count'] : null);
    }

    /**
     * The occurrences of the place and the period - the records of an
     * organism found there then, observed, collected or sampled, as GBIF's
     * occurrence search gives them -, each an entity with its time and its
     * place, its taxon related. Only the records GBIF has placed and dated,
     * of an organism present (not a recorded absence), are asked for.
     *
     * A BBox is the box (across the antimeridian, two polygons); a Point,
     * the circle of the query's radius around it (the configured one, 10 km,
     * by default); no place, the world. The period is in whole days: a time
     * stands for its day. Filters: OccurrenceQuery::TAXON (a key, 4CGXP,
     * which takes its subordinate taxa and synonyms; a legacy number,
     * 5219404, read in the frozen Backbone; or a scientific name, matched in
     * the checklist), COUNTRY (ISO 3166-1 alpha-2, or a list) and KIND
     * (GBIF's basis of record, or a list: human_observation,
     * preserved_specimen, machine_observation, material_sample...). GBIF
     * sorts nothing: a sort is refused. 300 a page at most, 100,000 deep;
     * the total is GBIF's count.
     */
    public function occurrences(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::TAXON, OccurrenceQuery::COUNTRY, OccurrenceQuery::KIND);
        if (null !== $query->sort) {
            throw NotSupportedException::operation('gbif', \sprintf('sort occurrences by "%s" (GBIF answers them in its own order)', $query->sort));
        }
        $offset = max(0, (int) $query->cursor);
        $limit = min(max(1, min(self::MAX_OCCURRENCES_PER_PAGE, $query->limit)), self::MAX_OCCURRENCE_DEPTH - $offset);
        if ($limit < 1) {
            throw NotSupportedException::operation('gbif', \sprintf('page past the %d first occurrences of a query (GBIF\'s search stops there; its downloads, which need an account, go further)', self::MAX_OCCURRENCE_DEPTH));
        }
        $parameters = $this->taxonFilter($filters[OccurrenceQuery::TAXON] ?? null) + ['checklistKey' => $this->checklist];
        foreach ((array) ($filters[OccurrenceQuery::COUNTRY] ?? []) as $country) {
            $parameters['country'][] = preg_match('~^[A-Za-z]{2}$~', (string) $country) ? strtoupper((string) $country) : throw new \InvalidArgumentException(\sprintf('A country is its ISO 3166-1 alpha-2 code ("ZA"), not "%s".', $country));
        }
        foreach ((array) ($filters[OccurrenceQuery::KIND] ?? []) as $kind) {
            $parameters['basisOfRecord'][] = strtoupper(str_replace(' ', '_', trim((string) $kind)));
        }
        $data = $this->getJson('v1/occurrence/search', array_filter($parameters, static fn (mixed $v) => null !== $v) + [
            'hasCoordinate' => 'true',
            'occurrenceStatus' => 'PRESENT',
            // A date range always, open-ended with "*": the records without a date are left out.
            'eventDate' => (null === $query->from ? '*' : $query->from->format('Y-m-d')).','.(null === $query->to ? '*' : $query->to->format('Y-m-d')),
            'limit' => $limit,
            'offset' => $offset ?: null,
        ] + $this->area($query)) ?? [];
        $records = array_values(array_filter($data['results'] ?? [], static fn (mixed $r) => \is_array($r) && isset($r['key'])));
        $entities = array_values(array_filter(array_map($this->toOccurrence(...), $records), static fn (Entity $e) => null !== $e->time && null !== $e->place));
        $next = $offset + \count($records);
        $more = false === ($data['endOfRecords'] ?? true) && [] !== $records && $next < self::MAX_OCCURRENCE_DEPTH;

        return new Page($entities, $more ? (string) $next : null, isset($data['count']) ? (int) $data['count'] : null);
    }

    /**
     * The taxon filter as GBIF's parameters: a key, a legacy number (read in
     * the legacy Backbone: no checklist named), or a scientific name.
     *
     * @return array<string, mixed>
     */
    private function taxonFilter(mixed $taxon): array
    {
        if (null === $taxon) {
            return [];
        }
        if ($taxon instanceof Identifier && Scheme::GBIF !== $taxon->scheme) {
            throw NotSupportedException::identifier('gbif', (string) $taxon, 'filter occurrences by');
        }
        if (!$taxon instanceof Identifier && !\is_string($taxon)) {
            throw new \InvalidArgumentException(\sprintf('A taxon is an Identifier of gbif, a key or a scientific name, not %s.', get_debug_type($taxon)));
        }
        $text = trim((string) ($taxon instanceof Identifier ? $taxon->value : $taxon));
        $key = $taxon instanceof Identifier ? $taxon : (preg_match(self::KEY, $text) || ctype_digit($text) ? Identifier::tryOf(Scheme::GBIF, $text) : Identifier::parse($text));
        if (null !== $key && Scheme::GBIF !== $key->scheme) {
            throw NotSupportedException::identifier('gbif', $text, 'filter occurrences by');
        }

        return match (true) {
            null === $key => ['scientificName' => $text],
            // A legacy number is the frozen Backbone's: GBIF finds nothing under it in the checklist.
            ctype_digit($key->value) => ['taxonKey' => $key->value, 'checklistKey' => null],
            default => ['taxonKey' => $key->value],
        };
    }

    /**
     * The place as GBIF's parameters: the ranges of a box, two polygons for
     * one across the antimeridian (GBIF takes no range that wraps), the
     * distance around a point.
     *
     * @return array<string, mixed>
     */
    private function area(OccurrenceQuery $query): array
    {
        $place = $query->place;
        if ($place instanceof Point) {
            return ['geoDistance' => \sprintf('%s,%s,%skm', $place->latitude, $place->longitude, $query->radius ?? $this->radius)];
        }
        if (null === $place) {
            return [];
        }
        if ($place->west <= $place->east) {
            return ['decimalLatitude' => $place->south.','.$place->north, 'decimalLongitude' => $place->west.','.$place->east];
        }
        $polygon = static fn (float $west, float $east): string => \sprintf('POLYGON((%2$s %1$s,%3$s %1$s,%3$s %4$s,%2$s %4$s,%2$s %1$s))', $place->south, $west, $east, $place->north);

        return ['geometry' => [$polygon($place->west, 180), $polygon(-180, $place->east)]];
    }

    /**
     * An occurrence record (the search's or the record's own, they are
     * alike): what was found, where and when, by whom, in which dataset.
     *
     * @param array<string, mixed> $r
     */
    private function toOccurrence(array $r): Entity
    {
        $key = (string) $r['key'];
        $classification = $r['classifications'][$this->checklist] ?? [];
        $usage = $classification['usage'] ?? [];
        $accepted = $classification['acceptedUsage'] ?? [];
        $ranks = [];
        foreach ($classification['classification'] ?? [] as $member) {
            if (isset($member['rank'], $member['name'])) {
                $ranks[(string) ($member['key'] ?? '')] = [strtolower((string) $member['rank']), (string) $member['name']];
            }
        }
        // The canonical name: the classification's own entry for the usage, else GBIF's interpretation.
        $name = self::text($ranks[(string) ($usage['key'] ?? '')][1] ?? null) ?? self::text($r['species'] ?? null) ?? self::text($r['scientificName'] ?? null) ?? $key;
        $properties = [];
        $add = static function (string $k, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($k, $label, $value, 'gbif');
            }
        };
        $metres = static fn (mixed $v): ?Quantity => is_numeric($v) ? new Quantity(0 + $v, 'm') : null;
        $word = static fn (mixed $v): ?string => \is_string($v) && '' !== $v ? strtolower(str_replace('_', ' ', $v)) : null;
        $add('taxon_name', 'Taxon name', $name);
        $add('scientific_name', 'Scientific name (with its authority)', self::text($usage['name'] ?? $r['scientificName'] ?? null));
        $add('taxon_rank', 'Taxon rank', $word($usage['rank'] ?? $r['taxonRank'] ?? null));
        foreach ($ranks as $memberKey => [$rank, $memberName]) {
            if ($memberKey !== (string) ($usage['key'] ?? '') && \in_array($rank, self::RANKS, true)) {
                $add($rank, ucfirst($rank), $memberName);
            }
        }
        $add('accepted_name', 'Accepted name', isset($accepted['key'], $usage['key']) && $accepted['key'] !== $usage['key'] ? self::text($accepted['name'] ?? null) : null);
        $add('basis_of_record', 'Basis of record', $word($r['basisOfRecord'] ?? null));
        $add('event_date', 'Date (as GBIF interprets it: its precision, or an interval)', self::text($r['eventDate'] ?? null));
        $add('individual_count', 'Individuals', isset($r['individualCount']) && is_numeric($r['individualCount']) ? (int) $r['individualCount'] : null);
        $add('sex', 'Sex', $word($r['sex'] ?? null));
        $add('life_stage', 'Life stage', $word($r['lifeStage'] ?? null));
        $add('country', 'Country', self::text($r['country'] ?? null));
        $add('country_code', 'Country (ISO 3166-1 alpha-2)', self::text($r['countryCode'] ?? null));
        $add('state_province', 'State or province', self::text($r['stateProvince'] ?? null));
        $add('locality', 'Locality', self::text($r['locality'] ?? $r['verbatimLocality'] ?? null));
        $add('coordinate_uncertainty', 'Coordinate uncertainty', $metres($r['coordinateUncertaintyInMeters'] ?? null));
        $add('depth', 'Depth', $metres($r['depth'] ?? null));
        $add('recorded_by', 'Recorded by', self::text($r['recordedBy'] ?? null));
        $add('identified_by', 'Identified by', self::text($r['identifiedBy'] ?? null));
        $add('dataset', 'Dataset', self::text($r['datasetName'] ?? null));
        $add('institution_code', 'Institution', self::text($r['institutionCode'] ?? null));
        $add('occurrence_id', 'The publisher\'s id for it', self::text($r['occurrenceID'] ?? null));
        $add('license', 'Licence', self::text($r['license'] ?? null));
        $add('information_withheld', 'Information withheld', self::text($r['informationWithheld'] ?? null));

        $image = null;
        foreach ($r['media'] ?? [] as $media) {
            $image ??= 'StillImage' === ($media['type'] ?? null) && \is_string($media['identifier'] ?? null) ? $media['identifier'] : null;
        }
        $place = is_numeric($r['decimalLatitude'] ?? null) && is_numeric($r['decimalLongitude'] ?? null)
            ? new Point((float) $r['decimalLatitude'], (float) $r['decimalLongitude'], is_numeric($r['elevation'] ?? null) ? (float) $r['elevation'] : null)
            : null;
        $taxon = Identifiers::of(Identifier::tryOf(Scheme::GBIF, $usage['key'] ?? null), Identifier::tryOf(Scheme::GBIF, $r['taxonKey'] ?? null));
        $acceptedTaxon = Identifiers::of(Identifier::tryOf(Scheme::GBIF, $accepted['key'] ?? null), Identifier::tryOf(Scheme::GBIF, $r['acceptedTaxonKey'] ?? null));
        $day = self::text($r['eventDate'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::GBIF_OCCURRENCE, $key),
            label: $name.' - '.implode(', ', array_filter([self::text($r['country'] ?? null), null === $day ? null : explode('T', $day)[0]])),
            domain: Domain::TAXONOMY,
            kind: 'occurrence',
            properties: $properties,
            url: 'https://www.gbif.org/occurrence/'.$key,
            image: $image,
            time: self::when($r['eventDate'] ?? null, $r['eventTime'] ?? null),
            place: $place,
            // An iNaturalist observation shared with GBIF is the same record: its page is the publisher's id.
            identifiers: Identifiers::of(self::inaturalist($r['occurrenceID'] ?? null)),
            source: 'gbif',
            related: array_filter([
                'taxon' => \count($taxon) ? $taxon : null,
                'accepted' => \count($acceptedTaxon) && $acceptedTaxon->keys() !== $taxon->keys() ? $acceptedTaxon : null,
            ]),
        );
    }

    /** The iNaturalist observation an occurrence is, when its publisher's id is the observation's page. */
    private static function inaturalist(mixed $occurrenceId): ?Identifier
    {
        $identifier = \is_string($occurrenceId) && str_contains($occurrenceId, 'inaturalist.org/observations/') ? Identifier::parse($occurrenceId) : null;

        return Scheme::INATURALIST_OBSERVATION === $identifier?->scheme ? $identifier : null;
    }

    /**
     * The instant of an event date as GBIF interprets it: the start of an
     * interval ("2010-01/2010-12"), the first day of a year or a month; the
     * offset of its eventTime when the date has none ("2026-01-01T09:15:50"
     * at "09:15:50+02:00"), else UTC.
     */
    private static function when(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        if (!\is_string($date) || '' === trim($date)) {
            return null;
        }
        $start = trim(explode('/', $date)[0]);
        $start = match (true) {
            (bool) preg_match('~^\d{4}$~', $start) => $start.'-01-01',
            (bool) preg_match('~^\d{4}-\d{2}$~', $start) => $start.'-01',
            default => $start,
        };
        if (str_contains($start, 'T') && !preg_match('~(?:Z|[+-]\d{2}:?\d{2})$~', $start) && \is_string($time) && preg_match('~(Z|[+-]\d{2}:?\d{2})$~', $time, $m)) {
            $start .= $m[1];
        }
        try {
            return new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** A scientific name matched: an exact or fuzzy match, never a higher rank (GBIF's answer to a name it does not know). */
    private function byName(string $name): ?Entity
    {
        $match = $this->match(['scientificName' => $name]);
        if (null === $match || !\in_array($match['diagnostics']['matchType'] ?? null, ['EXACT', 'FUZZY', 'VARIANT'], true)) {
            return null;
        }
        $accepted = $match['acceptedUsage']['key'] ?? null;
        if (true === ($match['synonym'] ?? false) && null !== $accepted) {
            $taxon = $this->taxon(['usageKey' => (string) $accepted]);

            return $taxon?->with(['synonyms' => array_values(array_unique([...$taxon->synonyms, (string) ($match['usage']['canonicalName'] ?? $match['usage']['name'] ?? $name)]))]);
        }

        return $this->taxon(null, $match);
    }

    /**
     * The taxon a match names, read whole: its record in the checklist, then
     * its NCBI id.
     *
     * @param array<string, string>|null $by    what to match on: usageKey, scientificNameID
     * @param array<string, mixed>|null   $match a match already made
     */
    private function taxon(?array $by, ?array $match = null): ?Entity
    {
        $match ??= null === $by ? null : $this->match($by);
        $key = $match['usage']['key'] ?? null;
        if (null === $key || 'NONE' === ($match['diagnostics']['matchType'] ?? null)) {
            return null;
        }
        $record = $this->getJson('v1/species', ['datasetKey' => $this->checklist, 'sourceId' => (string) $key])['results'][0] ?? [];
        $legacy = isset($record['nubKey']) ? (string) $record['nubKey'] : null;
        $ncbi = null;
        if ($this->ncbi && null !== $legacy) {
            foreach ($this->getJson('v1/species/'.$legacy.'/related', ['datasetKey' => self::ENA])['results'] ?? [] as $related) {
                $ncbi ??= self::ENA === ($related['datasetKey'] ?? null) ? Identifier::tryOf(Scheme::NCBI_TAXON, $related['taxonID'] ?? null) : null;
            }
        }

        return $this->toEntity($match, $record, $legacy, $ncbi);
    }

    /** @param array<string, string> $by */
    private function match(array $by): ?array
    {
        return $this->getJson('v2/species/match', $by + ['checklistKey' => $this->checklist]);
    }

    /**
     * @param array<string, mixed> $match  GBIF's v2 match: usage, classification, additionalStatus
     * @param array<string, mixed> $record the checklist's v1 record
     */
    private function toEntity(array $match, array $record, ?string $legacy, ?Identifier $ncbi): Entity
    {
        $usage = $match['usage'];
        $key = (string) $usage['key'];
        $name = self::text($usage['canonicalName'] ?? $record['canonicalName'] ?? null) ?? $key;
        $rank = isset($usage['rank']) ? strtolower((string) $usage['rank']) : null;

        $properties = [];
        $add = static function (string $k, string $label, ?string $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($k, $label, $value, 'gbif');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', self::text($usage['authorship'] ?? null));
        $add('scientific_name', 'Scientific name (with its authority)', self::text($usage['name'] ?? null));
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('taxonomic_status', 'Taxonomic status', isset($usage['status']) ? strtolower(str_replace('_', ' ', (string) $usage['status'])) : null);
        $add('nomenclatural_code', 'Nomenclatural code', isset($usage['code']) ? strtolower((string) $usage['code']) : null);
        $add('common_name', 'Common name', self::text($record['vernacularName'] ?? null));
        $add('published_in', 'Published in', self::text($record['publishedIn'] ?? null));
        foreach ($match['additionalStatus'] ?? [] as $status) {
            if ('IUCN' === ($status['datasetAlias'] ?? null) && isset($status['status'])) {
                $add('iucn_status', 'IUCN Red List category', ucfirst(strtolower(str_replace('_', ' ', (string) $status['status']))).(isset($status['statusCode']) ? ' ('.$status['statusCode'].')' : ''));
            }
        }
        $lineage = [];
        $parent = null;
        foreach ($match['classification'] ?? [] as $member) {
            if ((string) ($member['key'] ?? '') === $key) {
                continue;
            }
            $memberName = self::text($member['name'] ?? null);
            $memberRank = strtolower((string) ($member['rank'] ?? ''));
            if (null !== $memberName) {
                $lineage[] = $memberName;
                if (\in_array($memberRank, self::RANKS, true)) {
                    $add($memberRank, ucfirst($memberRank), $memberName);
                }
            }
            $parent = Identifier::tryOf(Scheme::GBIF, $member['key'] ?? null) ?? $parent;
        }
        $add('lineage', 'Lineage', $lineage ? implode(' > ', $lineage) : null);
        $accepted = true === ($match['synonym'] ?? false) ? ($match['acceptedUsage'] ?? null) : null;
        $add('accepted_name', 'Accepted name', \is_array($accepted) ? self::text($accepted['name'] ?? null) : null);

        $related = array_filter([
            // A synonym's classification is its accepted taxon's: the accepted one, not a parent.
            'parent' => null === $parent || \is_array($accepted) ? null : Identifiers::of($parent),
            'accepted' => \is_array($accepted) && null !== ($a = Identifier::tryOf(Scheme::GBIF, $accepted['key'] ?? null)) ? Identifiers::of($a) : null,
        ]);
        $common = self::text($record['vernacularName'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::GBIF, $key),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: null === $common || mb_strtolower($common) === mb_strtolower($name) ? [] : [$common],
            properties: $properties,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::GBIF, $legacy), $ncbi),
            url: 'https://www.gbif.org/taxon/'.rawurlencode($key),
            source: 'gbif',
            related: $related,
        );
    }

    /** @param array<string, mixed> $record a result of v1/species/search */
    private function fromSearch(array $record): Entity
    {
        $key = (string) $record['taxonID'];
        $name = self::text($record['canonicalName'] ?? $record['scientificName'] ?? null) ?? $key;
        $rank = isset($record['rank']) ? strtolower((string) $record['rank']) : null;
        $properties = [];
        $add = static function (string $k, string $label, ?string $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($k, $label, $value, 'gbif');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', self::text($record['authorship'] ?? null));
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('taxonomic_status', 'Taxonomic status', isset($record['taxonomicStatus']) ? strtolower(str_replace('_', ' ', (string) $record['taxonomicStatus'])) : null);
        foreach (['kingdom', 'phylum', 'class', 'order', 'family', 'genus'] as $level) {
            if (($record[$level] ?? null) !== $name) {
                $add($level, ucfirst($level), self::text($record[$level] ?? null));
            }
        }
        $common = [];
        foreach ($record['vernacularNames'] ?? [] as $vernacular) {
            if ($this->language === ($vernacular['language'] ?? null) && null !== ($v = self::text($vernacular['vernacularName'] ?? null))) {
                $common[mb_strtolower($v)] ??= $v;
            }
        }
        $add('common_name', 'Common name', array_values($common)[0] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::GBIF, $key),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: array_values($common),
            properties: $properties,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::GBIF, $record['nubKey'] ?? null)),
            url: 'https://www.gbif.org/taxon/'.rawurlencode($key),
            source: 'gbif',
        );
    }
}
