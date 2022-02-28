<?php declare(strict_types=1);

namespace Nadybot\User\Modules\NAME_GENERATOR;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Http,
	HttpResponse,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;

/**
 * A command to generate new character names.
 *
 * @author Yakachi (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command:     'suggestname',
		accessLevel: 'all',
		description: 'Generate a random character name',
	)
]

class NameGeneratorController extends ModuleInstance {
	#[NCA\Inject()]
	public PlayerManager $playerManager;

	#[NCA\Inject()]
	public Http $http;

	/**
	 * The URL to the name generator webpage with the name length as a parameter
	 */
	public const NAME_GENERATOR_URL = 'https://www.fantasynamegen.com/sf/%s/';

	/**
	 * An array of all valid lengths
	 * @var string[] LENGTHS
	 */
	public const LENGTHS = [
		'short',
		'medium',
		'long',
	];

	/**
	 * Try to parse the array of generated names from the name generator HTML
	 * @return string[]
	 */
	public function nameGeneratorHtmlToNames(string $html): array {
		preg_match_all("/<li>([A-z]{4,12})<\/li>/", $html, $matches);
		return $matches[1];
	}

	/**
	 * Generate a character name with an optional length
	 */
	#[NCA\HandlesCommand("suggestname")]
	public function nameCommand(
		CmdContext $context,
		#[NCA\StrChoice("short", "medium", "long")] ?string $length
	): void {
		$length ??= static::LENGTHS[array_rand(static::LENGTHS)];
		$this->http
				->get(sprintf(static::NAME_GENERATOR_URL, $length))
				->withTimeout(5)
				->withCallback([$this, "sendName"], $context);
	}

	public function sendName(HttpResponse $response, CmdContext $context): void {
		if ($response->error) {
			$context->reply($response->error);
			return;
		}
		$names = $this->nameGeneratorHtmlToNames($response->body ?? "");
		if (empty($names)) {
			$msg = "No names were found. If this occurs too often, please contact the author of the module.";
			$context->reply($msg);
			return;
		}

		$this->playerManager->massGetByNameAsync(
			/** @param string[] $names */
			function (array $names) use ($context): void {
				$this->showNameSuggestions($names, $context);
			},
			$names
		);
	}

	/** @param string[] $names */
	protected function showNameSuggestions(array $names, CmdContext $context): void {
		$names = (new Collection($names))
			->filter(fn(?object $data): bool => $data === null)
			->keys();
		if ($names->isEmpty()) {
			$context->reply("No unused names found, please try again.");
			return;
		}
		$msg = "Name suggestions for your next character: <highlight>".
			$names->join("<end>, <highlight>", "<end> or <highlight>") . "<end>.";
		$context->reply($msg);
	}
}
