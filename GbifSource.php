<?php

namespace Omnilab\Gbif;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GBIF's taxonomy, through its API (api.gbif.org): a taxon by its key, by
 * its legacy GBIF Backbone number, by its NCBI Taxonomy id or by a
 * scientific name GBIF matches; its name, authorship, rank, status, IUCN
 * Red List category, common name, place of publication and classification;
 * its legacy key and its NCBI id as cross-references, its parent (and, for
 * a synonym, the accepted taxon) as related things; and a search.
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

    /** The ranks given each a property, from the root down. */
    private const RANKS = ['domain', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus'];

    /** A key of the Catalogue of Life: the digits 2 to 9 and the consonants, upper case. */
    private const KEY = '~^[2-9BCDFGHJKLMNPQRSTVWXYZ]{1,6}$~';

    /**
     * @param string $checklist  the GBIF checklist to answer from (the Catalogue of Life eXtended Release)
     * @param bool   $ncbi       whether the NCBI id is read (one call more)
     * @param string $language   the language of the common name, ISO 639-2 ("eng", "fra"), for a search's results
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $checklist = self::CHECKLIST,
        private readonly bool $ncbi = true,
        private readonly string $language = 'eng',
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
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A taxon by its key (4CGXP, gbif:4CGXP, its page), its legacy number
     * (5219404, its page gbif.org/species/), its NCBI id (an Identifier of
     * ncbi.taxon, taxonomy:9689) or a scientific name ("Panthera leo";
     * a synonym gives the accepted taxon). A common name is no scientific
     * name: GBIF matches none ("lion").
     */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, [Scheme::GBIF, Scheme::NCBI_TAXON], true) ? $id : throw NotSupportedException::identifier('gbif', (string) $id);
        } else {
            $text = trim($id);
            $identifier = preg_match(self::KEY, $text) || ctype_digit($text) ? Identifier::tryOf(Scheme::GBIF, $text) : Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, [Scheme::GBIF, Scheme::NCBI_TAXON], true)) {
                throw NotSupportedException::identifier('gbif', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
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
