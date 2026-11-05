<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Search\IFilter;
use OCP\Search\IFilterCollection;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Searches the contents of the person's files with Findling (appid findling): full text, OCR of
 * scans and meaning, through its provider of Nextcloud's search bar, as the asking person. The
 * provider checks every hit against the person's rights; each hit is resolved here once more
 * (Conversation::userFile), so the model gets only files it may then read with read_file.
 *
 * Optional: without Findling installed and enabled for the person, nothing here is offered.
 */
class ContentSearch {
	public const APP = 'findling';
	/** Findling's provider of the search bar (OCA\Findling\Search\Provider, id findling). */
	private const PROVIDER = 'OCA\\Findling\\Search\\Provider';
	/** Hits handed to the model by default and at most. */
	public const HITS = 10;
	public const HITS_MAX = 25;
	/** Characters of one hit's passage. */
	private const PASSAGE_CHARS = 600;
	/** Findling's type groups (its SearchFilters::TYPES), the filter types. */
	public const TYPES = ['pdf', 'office', 'text', 'image', 'email'];

	public function __construct(
		private IAppManager $appManager,
		private IUserManager $userManager,
		private ContainerInterface $container,
		private Conversation $conversation,
		private LoggerInterface $logger,
	) {
	}

	/** Whether Findling searches for this person. */
	public function available(string $userId): bool {
		$user = $this->userManager->get($userId);
		return $user !== null && $this->appManager->isEnabledForUser(self::APP, $user) && $this->provider() !== null;
	}

	private function provider(): ?IProvider {
		try {
			$provider = $this->container->get(self::PROVIDER);
		} catch (\Throwable $e) {
			return null;
		}
		return $provider instanceof IProvider ? $provider : null;
	}

	/**
	 * The person's files whose contents match: id, path, type, size, date and the passage that
	 * matched. $folder narrows to a folder of theirs, $types to Findling's type groups, $since
	 * (Y-m-d) to files changed since.
	 *
	 * @return list<array{id: int, path: string, mime: string, size: int, modified: string, passage: string}>|string
	 */
	public function search(string $userId, string $query, string $folder = '', array $types = [], string $since = '', int $limit = self::HITS): array|string {
		$query = trim($query);
		if ($query === '') {
			return 'Give the words or the meaning to look for (query).';
		}
		$user = $this->userManager->get($userId);
		$provider = $user === null ? null : $this->provider();
		if ($user === null || $provider === null || !$this->appManager->isEnabledForUser(self::APP, $user)) {
			return 'The search in file contents (Findling) is not available here; find_files searches the names.';
		}
		$filters = [];
		$folder = trim($folder, " \t/");
		if ($folder !== '') {
			$filters['folder'] = '/' . $folder;
		}
		$types = array_values(array_intersect(self::TYPES, array_map('strtolower', array_map('strval', $types))));
		if ($types !== []) {
			$filters['types'] = implode(',', $types);
		}
		$sinceDate = $since === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $since);
		if ($sinceDate !== false) {
			$filters[IFilter::BUILTIN_SINCE] = $sinceDate;
		}
		$limit = max(1, min(self::HITS_MAX, $limit));
		try {
			$result = $provider->search($user, self::query($query, $filters, $limit))->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: Findling\'s search failed: ' . $e->getMessage(), ['exception' => $e]);
			return 'The search in file contents failed; try again later, or find_files for the names.';
		}
		$found = [];
		foreach ($result['entries'] ?? [] as $entry) {
			$entry = $entry instanceof \JsonSerializable ? $entry->jsonSerialize() : (array)$entry;
			$id = (int)($entry['attributes']['fileId'] ?? 0);
			// Findling's last entry is a link to its own results page, no file.
			$file = $id > 0 ? $this->conversation->userFile($userId, $id) : null;
			if ($file === null) {
				continue;
			}
			$path = $this->conversation->relativePath($userId, $file);
			$subline = trim((string)($entry['subline'] ?? ''));
			$found[] = ['id' => $id, 'path' => $path, 'mime' => $file->getMimetype(), 'size' => $file->getSize(),
				'modified' => date('Y-m-d', $file->getMTime()),
				'passage' => $subline === $path || $subline === '' ? '' : mb_substr(preg_replace('/\s+/u', ' ', $subline) ?? '', 0, self::PASSAGE_CHARS)];
			if (count($found) >= $limit) {
				break;
			}
		}
		return $found;
	}

	/** The query Findling's provider reads: the term, the filters by name, a page from the start. */
	private static function query(string $term, array $filters, int $limit): ISearchQuery {
		$collection = new class($filters) implements IFilterCollection {
			/** @var array<string, IFilter> */
			private array $filters = [];

			public function __construct(array $values) {
				foreach ($values as $name => $value) {
					$this->filters[$name] = new class($value) implements IFilter {
						public function __construct(
							private mixed $value,
						) {
						}

						public function get(): mixed {
							return $this->value;
						}
					};
				}
			}

			public function has(string $name): bool {
				return isset($this->filters[$name]);
			}

			public function get(string $name): ?IFilter {
				return $this->filters[$name] ?? null;
			}

			public function getIterator(): \Traversable {
				return new \ArrayIterator($this->filters);
			}

			public function count(): int {
				return count($this->filters);
			}
		};
		return new class($term, $collection, $limit) implements ISearchQuery {
			public function __construct(
				private string $term,
				private IFilterCollection $filters,
				private int $limit,
			) {
			}

			public function getTerm(): string {
				return $this->term;
			}

			public function getFilter(string $name): ?IFilter {
				return $this->filters->get($name);
			}

			public function getFilters(): IFilterCollection {
				return $this->filters;
			}

			public function getSortOrder(): int {
				return ISearchQuery::SORT_DATE_DESC;
			}

			public function getLimit(): int {
				return $this->limit;
			}

			public function getCursor(): int|string|null {
				return null;
			}

			public function getRoute(): string {
				return '';
			}

			public function getRouteParameters(): array {
				return [];
			}
		};
	}
}
