<?php declare(strict_types=1);

namespace Nadybot\User\Modules;

use Nadybot\Core\{
	CommandReply,
	Http,
	HttpResponse,
	Nadybot,
};

/**
 * A command to generate new character names.
 *
 * @author Yakachi (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'suggestname',
 *		accessLevel = 'all',
 *		description = 'Generate a random character name',
 *		help        = 'namegenerator.txt'
 *	)
 */
class NameGeneratorController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
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
	 * The !name command generates a character name for an optional length
	 *
	 * @HandlesCommand("suggestname")
	 * @Matches("/^suggestname$/i")
	 * @Matches("/^suggestname (short|medium|long)$/i")
	 */
	public function nameCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$length = static::LENGTHS[array_rand(static::LENGTHS)];
		if (isset($args[1])) {
			$length = $args[1];
		}
		$this->http
				->get(sprintf(static::NAME_GENERATOR_URL, $length))
				->withTimeout(5)
				->withCallback([$this, "sendName"], $sendto);
	}

	public function sendName(HttpResponse $response, CommandReply $sendto): void {
		if ($response->error) {
			$sendto->reply($response->error);
			return;
		}
		$names = $this->nameGeneratorHtmlToNames($response->body ?? "");
		if (empty($names)) {
			$msg = "No names were found. If this occurs too often, please contact the author of the module.";
			$sendto->reply($msg);
			return;
		}

		$name = $names[array_rand($names)];
		$msg = "You should call your next character <highlight>{$name}<end>.";
		$sendto->reply($msg);
	}
}
