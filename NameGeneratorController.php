<?php declare(strict_types=1);

namespace Nadybot\User\Modules\NAME_GENERATOR;

use function Amp\call;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Amp\Promise;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\DBSchema\Player;

use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	UserException,
};
use Throwable;

/**
 * A command to generate new character names.
 *
 * @author Yakachi (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'suggestname',
		accessLevel: 'all',
		description: 'Generate a random character name',
	)
]

class NameGeneratorController extends ModuleInstance {
	/** The URL to the name generator webpage with the name length as a parameter */
	public const NAME_GENERATOR_URL = 'https://www.fantasynamegen.com/sf/%s/';

	/**
	 * An array of all valid lengths
	 *
	 * @var string[] LENGTHS
	 */
	public const LENGTHS = [
		'short',
		'medium',
		'long',
	];
	#[NCA\Inject()]
	public PlayerManager $playerManager;

	#[NCA\Inject()]
	public HttpClientBuilder $http;

	/**
	 * Try to parse the array of generated names from the name generator HTML
	 *
	 * @return string[]
	 */
	public function nameGeneratorHtmlToNames(string $html): array {
		preg_match_all("/<li>([A-z]{4,12})<\/li>/", $html, $matches);
		return $matches[1];
	}

	/** Generate a character name with an optional length */
	#[NCA\HandlesCommand("suggestname")]
	public function nameCommand(
		CmdContext $context,
		#[NCA\StrChoice("short", "medium", "long")] ?string $length
	): Generator {
		$length ??= static::LENGTHS[array_rand(static::LENGTHS)];
		$freeNames = yield $this->getFreeNames(strtolower($length));
		$msg = $this->renderNameSuggestions(...$freeNames);
		$context->reply($msg);
	}

	/** @return Promise<string[]> */
	private function getFreeNames(string $length): Promise {
		return call(function () use ($length): Generator {
			$client = $this->http->buildDefault();

			try {
				/** @var Response */
				$response = yield $client->request(new Request(sprintf(static::NAME_GENERATOR_URL, $length)));
				$body = yield $response->getBody()->buffer();
			} catch (Throwable $e) {
				throw new UserException("Unexpected error calling the name API. Try again later, or come up with your own name.");
			}
			$names = $this->nameGeneratorHtmlToNames($body);
			if (empty($names)) {
				throw new UserException("No names were found. If this occurs too often, please contact the author of the module.");
			}
			$lookups = [];
			foreach ($names as $name) {
				$lookups[$name] = $this->playerManager->byName($name);
			}

			/** @var array<string,?Player> */
			$results = yield $lookups;

			/** @var string[] */
			$freeNames = array_keys(
				array_filter(
					$results,
					fn (?Player $data): bool => $data === null,
				)
			);
			return $freeNames;
		});
	}

	private function renderNameSuggestions(string ...$names): string {
		$names = new Collection($names);
		if ($names->isEmpty()) {
			return "No unused names found, please try again.";
		}
		$msg = "Name suggestions for your next character: <highlight>".
			$names->join("<end>, <highlight>", "<end> or <highlight>") . "<end>.";
		return $msg;
	}
}
