<?php declare(strict_types=1);

namespace Nadybot\User\Modules\NAME_GENERATOR_MODULE;

use function Amp\async;
use function Amp\Future\awaitAll;
use Amp\{CancelledException, TimeoutCancellation};
use Amp\Http\Client\{HttpClientBuilder, Request};
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\StrChoice,
	CmdContext,
	DBSchema\Player,
	Exceptions\UserException,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Safe,
	Types\AccessLevel,
};
use Psr\Log\LoggerInterface;
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
		accessLevel: AccessLevel::Guest,
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
	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private HttpClientBuilder $http;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/**
	 * Try to parse the array of generated names from the name generator HTML
	 *
	 * @return string[]
	 */
	public function nameGeneratorHtmlToNames(string $html): array {
		return Safe::pregMatchAll('|<li>([A-Za-z]{4,12})</li>|', $html)[1];
	}

	/**
	 * Generate a character name with an optional length
	 *
	 * Character names suggested are guaranteed to be available in AO.
	 */
	#[NCA\HandlesCommand('suggestname')]
	public function nameCommand(
		CmdContext $context,
		#[StrChoice('short', 'medium', 'long')] ?string $length
	): void {
		$length ??= static::LENGTHS[array_rand(static::LENGTHS)];
		$freeNames = $this->getFreeNames(strtolower($length));
		$msg = $this->renderNameSuggestions(...$freeNames);
		$context->reply($msg);
	}

	/** @return list<string> */
	private function getFreeNames(string $length): array {
		$client = $this->http->buildDefault();

		try {
			$request = new Request(sprintf(static::NAME_GENERATOR_URL, $length));
			$response = $client->request($request, new TimeoutCancellation(10));
			if ($response->getStatus() !== 200) {
				throw new Exception('ignore me');
			}
			$body = $response->getBody()->buffer();
		} catch (CancelledException) {
			throw new UserException('Timeout calling the name API. Try again later, or come up with your own name.');
		} catch (Throwable) {
			throw new UserException('Unexpected error calling the name API. Try again later, or come up with your own name.');
		}
		$names = $this->nameGeneratorHtmlToNames($body);
		if (count($names) === 0) {
			throw new UserException('No names were found. If this occurs too often, please contact the author of the module.');
		}
		$lookups = [];
		foreach ($names as $name) {
			$lookups[$name] = async($this->playerManager->byName(...), $name);
		}

		[$errors, $results] = awaitAll($lookups);

		if (count($results) === 0) {
			$exception = array_shift($errors);
			if ($exception !== null) {
				$this->logger->error('Error looking up names: {error}', [
					'error' => $exception->getMessage(),
					'exception' => $exception,
				]);
				throw $exception;
			}
			throw new UserException('No names were found. If this occurs too often, please contact the author of the module.');
		}

		/** @var string[] */
		$freeNames = array_keys(
			array_filter(
				$results,
				static fn (?Player $data): bool => $data === null,
			)
		);
		return $freeNames;
	}

	private function renderNameSuggestions(string ...$names): string {
		$names = new Collection($names);
		if ($names->isEmpty()) {
			return 'No unused names found, please try again.';
		}
		$msg = 'Name suggestions for your next character: <highlight>'.
			$names->join('<end>, <highlight>', '<end> or <highlight>') . '<end>.';
		return $msg;
	}
}
