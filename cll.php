<?php
$version = '1.1.2';
$openai_key = getenv( 'OPENAI_API_KEY', true );
$supported_models = array();
$ansi = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

$options = getopt( 'ds:li:p:vhfm:r:', array( 'help', 'version' ), $initial_input );

if ( ! isset( $options['m'] ) ) {
	putenv('RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1');
	$online = gethostbyname( 'api.openai.com.' ) !== 'api.openai.com.';
} else {
	$online = true;
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

function dont_auto_complete ($input, $index) { return []; }
function output_message( $message ) {
	static $old_message = '';
	if ( $message === '') {
		$old_message = '';
	}
	static $chunks = array();
	if ( $message === '---OUTPUTOKENS' ) {
		//lightgray
		echo "\033[37m", PHP_EOL, json_encode( $chunks ), PHP_EOL, "\033[m";
		$chunks = array();
		return;
	} else {
		$chunks[] = $message;
	}
	static $state = array(
		'maybe_bold' => false,
		'bold' => false,
		'headline' => false,
		'trimnext' => false,
		'inline_code' => false,
		'in_code_block' => false,
		'code_block_start' => false,
		'maybe_code_block_end' => false,
	);

	$message = $old_message . $message;
	$i = strlen( $old_message );
	$old_message = $message;
	$length = strlen( $message );

	while ($i < $length) {

		// Check for the start of a code block
		$last_php_eol = $i > 1 ? strrpos( $message, PHP_EOL, $i - $length - 1 ) : false;
		if ($i > 2 && substr($message, $i - 2, 3) === '```'  && $last_php_eol !== false && trim( substr( $message, $last_php_eol, $i - $last_php_eol-2)) === '') {

			if ( $state['in_code_block'] ) {
				echo "\033[m";
				if ( false !== $state['maybe_code_block_end']) {
		   			echo substr( $message, $state['maybe_code_block_end'], 2);
					$state['maybe_code_block_end'] = false;
				}
				$state['in_code_block'] = false;
			} else {
				$state['code_block_start'] = true;
				echo substr($message, $i - 2, 2);
			}
			echo $message[$i++];
			continue;
		}

		// If we're in a code block, just output the text as is
		if ($state['code_block_start'] ) {
			echo $message[$i];
			if ($message[$i] === PHP_EOL) {
				$state['code_block_start'] = false;
				$state['in_code_block'] = true;
				// show in darkgrey
				echo "\033[90m";
			}
			$i++;
			continue;
		}

		if ($state['in_code_block']) {
			if ( false === $state['maybe_code_block_end'] && $message[$i] === '`' && $last_php_eol !== false && trim( substr( $message, $last_php_eol, $i - $last_php_eol-1) ) === '') {
				$state['maybe_code_block_end'] = $i;
				$i++;
				continue;
			}
			if ( false !== $state['maybe_code_block_end'] && substr( $message, $i-1, 2) === '``' && $last_php_eol !== false && trim( substr( $message, $last_php_eol, $i - $last_php_eol-2) ) === '') {
				$i++;
				continue;
			}
			echo $message[$i++];
			continue;
		}

		// Process bold and headline markers only outside code blocks
		if ($message[$i] === '*') {
			// The second *.
			if ( $state['maybe_bold'] ) {
				$state['bold'] = !$state['bold'];
				echo $state['bold'] ? "\033[1m" : "\033[m";
				$state['maybe_bold'] = false;
			} else {
				$state['maybe_bold'] = true;
			}
			$i++; // Move past the bold indicator
			continue;
		} elseif ( $state['maybe_bold'] ) {
			// false alarm.
			echo '*';
			$state['maybe_bold'] = false;
		}

		// Process bold and headline markers only outside code blocks
		if ($i > 1 && substr($message, $i-1, 2) === '**' && substr($message, $i - 2, 1) === PHP_EOL) {
			$state['bold'] = !$state['bold'];
			echo $state['bold'] ? "\033[1m" : "\033[m";
			$i++; // Move past the bold indicator
			continue;
		}

		if ( substr($message, $i, 1) === '`') {
			$state['inline_code'] = !$state['inline_code'];
			echo $state['inline_code'] ? "\033[34m" : "\033[m";
			$i++;
			continue;
		}

		if ( $state['trimnext'] ) {
			if (trim($message[$i]) == '') {
				$i++;
				continue;
			}
			$state['trimnext'] = false;
		}

		if ( substr($message, $i, 1) === '#' && substr($message, $i - 1, 1) === PHP_EOL) {
			// Start of a headline
			$state['headline'] = true;
			$state['trimnext'] = true;
			echo "\033[4m";
			while ( $i < $length && ( $message[$i] === '#' || $message[$i] === ' ') ) {
				$i++;
			}
			continue;
		}

		// Reset states on new lines
		if ($message[$i] === PHP_EOL) {
			if ($state['bold'] || $state['headline']) {
				echo "\033[m"; // Reset bold and headline
				$state['bold'] = false;
				$state['headline'] = false;
			}
		}

		echo $message[$i++];
	}
}

readline_completion_function("dont_auto_complete");

$readline_history_file = __DIR__ . '/.history';
$history_base_directory = __DIR__ . '/chats/';
if ( ! file_exists( $history_base_directory ) ) {
	if ( ! mkdir( $history_base_directory ) ) {
		echo 'Could not create history directory: ', $history_base_directory, PHP_EOL;
		exit( 1 );
	}
}
$time = time();
$history_directory = $history_base_directory . date( 'Y/m', $time );

$system = false;

if ( $online && ! empty( $openai_key ) ) {
	// curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/models' );
	// curl_setopt(
	// 	$ch,
	// 	CURLOPT_HTTPHEADER,
	// 	array(
	// 		'Content-Type: application/json',
	// 		'Authorization: Bearer ' . $openai_key,
	// 	)
	// );

	// $response = curl_exec($ch);
	// $data = json_decode($response, true);

	// foreach ($data['data'] as $model) {
	// 	if ( false !== strpos( $model['id'], 'gpt' ) ) {
	//     $supported_models[ $model['id'] ]  = 'OpenAI';
	// 	}
	// }

	$supported_models['gpt-3.5-turbo'] = 'OpenAI';
	$supported_models['gpt-3.5-turbo-16k'] = 'OpenAI';
	$supported_models['gpt-4o-mini'] = 'OpenAI';
	$supported_models['gpt-4o'] = 'OpenAI';
}

curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/api/tags' );
$ollama_models = json_decode( curl_exec( $ch ), true );
if ( isset( $ollama_models['models'] ) ) {
	foreach ( $ollama_models['models'] as $m ) {
		$supported_models[ $m['name'] ] = 'Ollama (local)';
	}
}

if ( empty( $supported_models ) ) {
	echo 'No supported models found.', PHP_EOL, PHP_EOL;
	echo 'If you want to use ChatGPT, please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL, PHP_EOL;
	echo 'If you want to use Ollama, please make sure it is accessible on localhost:11434', PHP_EOL;
	exit( 1 );
}

$model_weight = array_flip( array_reverse( array( 'gpt-4o-mini', 'gemma', 'llama3', 'llama2' ) ) );
uksort( $supported_models, function( $a, $b ) use ( $model_weight ) {
	$a_weight = $b_weight = -1;
	foreach ( $model_weight as $model => $weight ) {
		if ( 0 === strpos( $a, $model ) ) {
			$a_weight = $weight;
		} elseif ( 0 === strpos( $b, $model ) ) {
			$b_weight = $weight;
		}
	}

	if ( $a_weight > $b_weight ) {
		return -1;
	}

	if ( $a_weight < $b_weight ) {
		return 1;
	}

	return 0;
} );

$model = key( $supported_models );

$supported_models_list = implode( ', ', array_keys( $supported_models ) );

if ( isset( $options['version'] ) ) {
	echo basename( $_SERVER['argv'][0] ), ' version ', $version, PHP_EOL;
	exit( 1 );
}

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	$offline = ! $online ? "(we're offline)" : '';
	$self = basename( $_SERVER['argv'][0] );
	echo <<<USAGE
Usage: $self [-l] [-f] [-r [number|searchterm]] [-m model] [-s system_prompt] [-i input_file_s] [-p picture_file] [conversation_input]

Options:
  -l                 Resume last conversation.
  -r number|search   Resume a previous conversation and list 'number' conversations or search them.
  -d                 Ignore the model's last answer. Useful when combining with -l to ask the question to another model.
  -v                 Be verbose.
  -f                 Allow file system writes for suggested file content by the AI.
  -m [model]         Use a specific model. Default: $model
  -i input_file(s)   Read these files and add them to the prompt.
  -p picture_file    Add an picture as input (only gpt-4o).
  -s system_prompt   Specify a system prompt preceeding the conversation.

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".

Example usage:
  $self -l
    Resumes the last conversation.

  $self -ld -m llama2
    Reasks the previous question.

  $self -r 5
    Resume a conversation and list the last 5 to choose from.

  $self -r hello
    Resume a conversation and list the last 10 containing "hello" to choose from.

  $self -s "Only respond in emojis"
    Have an interesting conversation 🙂

  $self Tell me a joke
    Starts a new conversation with the given message.

  $self -m gpt-3.5-turbo-16k
    Use a ChatGPT model with 16k tokens instead of 4k.
    Supported modes: $supported_models_list $offline


USAGE;
	exit( 1 );
}
$messages = array();
$initial_input = trim( implode( ' ', array_slice( $_SERVER['argv'], $initial_input ) ) . ' ' );
$stdin = false;

$fp_stdin = fopen( 'php://stdin', 'r' );
$stat = fstat( $fp_stdin );
if ( $stat['size'] > 0 ) {
	$initial_input .= PHP_EOL;
	while ( $in = fread( $fp_stdin, $stat['size'] ) ) {
		$initial_input .= $in;
	}
	$stdin = true;
}
fclose( $fp_stdin );

$fp = false;

if ( isset( $options['m'] ) ) {
	$model = false;
	if ( isset( $supported_models[$options['m']] ) ) {
		$model = $options['m'];
	}
	if ( ! $model && $options['m'] ) {
		foreach ( array_keys( $supported_models ) as $m ) {
			if ( false !== strpos( $m, $options['m'] ) ) {
				$model = $m;
				break;
			}
		}
	}
	if ( ! $model ) {
		foreach ($supported_models as $m => $provider ) {
			if ( $provider === $options['m'] ) {
				$model = $m;
				break;
			}
		}
	}
	if ( ! $model ) {
		fprintf( STDERR, 'Supported Models: ' . $supported_models_list . PHP_EOL );
		exit( 1 );
	}
}
if ( ! $stdin || isset( $options['v'] ) ) {
	fprintf( STDERR, 'Model: ' . $model . ' via ' . $supported_models[$model] . PHP_EOL );
}

$full_history_file = $history_directory . '/history.' . $time . '.' . preg_replace( '/[^a-z0-9]+/', '-', $model ) . '.txt';

if ( isset( $options['l'] ) ) {
	$options['r'] = 1;
}

$sel = false;
$last_conversations = array();

if ( isset( $options['r'] ) ) {
	$search = false;
	if ( ! is_numeric( $options['r'] ) ) {
		$search = $options['r'];
		$options['r'] = 10;
	}

	$options['r'] = intval( $options['r'] );
	if ( $options['r'] <= 0 ) {
		$options['r'] = 10;
	}
	$history_files = array();
	for ( $i = 0; $i > -300; $i -= 20 ) {
		$more_history_files = array_flip( glob( $history_base_directory . date( 'Y/m', $time - $i ) . '/history.*' ) );
		if ( $search ) {
			$more_history_files = array_filter( $more_history_files, function( $file ) use ( $search ) {
				$file_contents = file_get_contents( $file );
				return false !== stripos( $file_contents, $search );
			}, ARRAY_FILTER_USE_KEY );
		}
		$history_files = array_merge( $history_files, $more_history_files );
		if ( count( $history_files ) >= $options['r'] ) {
			break;
		}
	}
	krsort( $history_files );

	$length = $options['r'];
	if ( isset( $options['l'] ) ) {
		echo 'Resuming the last conversation.', PHP_EOL;
	} else {
		echo 'Resuming a conversation. ';
	}
	$sel = 'm';
	$c = 0;
	while ( 'm' === $sel ) {
		$last_history_files = array_slice( array_keys( $history_files ), $c, $length );
		if ( empty( $last_history_files ) ) {
			if ( $c ) {
				echo 'No more conversations.', PHP_EOL;
			} else {
				echo 'No previous conversation. Starting a new one:', PHP_EOL;
				$sel = 0;
				break;
			}
		}

		if ( empty( $last_conversations ) && ! isset( $options['l'] ) ) {
			echo 'Please choose one: ', PHP_EOL;
		}

		if ( !empty( $last_history_files ) ) {
			$length = 10;
			foreach ( $last_history_files as $k => $last_history_file ) {
				$filename_parts = explode( '.', $last_history_file );
				$used_model = $filename_parts[2];
				if ( 'txt' === $used_model ) {
					$used_model = '';
				} else {
					if ( ! isset( $options['m'] ) ) {
						$model = str_replace( 'gpt-3-5-', 'gpt-3.5-', $used_model );
						$model = str_replace( '-latest', ':latest', $used_model );
					}
					$used_model .= ', ';
				}
				$ago = '';
				$unix_timestamp = $filename_parts[1];
				if ( is_numeric( $unix_timestamp ) ) {
					$ago_in_seconds = $time - $unix_timestamp;
					if ( $ago_in_seconds > 60 * 60 * 24 ) {
						$ago = round( $ago_in_seconds / ( 60 * 60 * 24 ) ) . 'd ago, ';
					} elseif ( $ago_in_seconds > 60 * 60 ) {
						$ago = round( $ago_in_seconds / ( 60 * 60 ) ) . 'h ago, ';
					} elseif ( $ago_in_seconds > 60 ) {
						$ago = round( $ago_in_seconds / 60 ) . 'm ago, ';
					} else {
						$ago = $ago_in_seconds . 's ago, ';
					}
				}
				$conversation_contents = file_get_contents( $last_history_file );
				$split = preg_split( '/^>(?: ([^\n]*)|>> (.*)\n\.)\n\n/ms', trim( $conversation_contents ), -1, PREG_SPLIT_DELIM_CAPTURE );
				$split = array_filter( $split );
				$split = array_values( $split );

				if ( count( $split ) < 2 ) {
					// echo 'Empty history file: ', $last_history_file, PHP_EOL;
					unset( $history_files[ $last_history_file ] );
					unset( $last_history_files[ $k ] );
					continue;
				}
				$s = array_shift( $split );
				if ( substr( $s, 0, 7 ) === 'System:' ) {
					$split[0] = $s . $split[0];
				} else {
					array_unshift( $split, $s );
				}

				$history_files[ $last_history_file ] = $split;
				$answers = intval( count( $history_files[ $last_history_file ] ) / 2 );

				$c = $c + 1;

				if ( ! isset( $options['l'] ) ) {
					echo PHP_EOL;
					echo $c, ') ';
					if ( $ansi ) {
						echo "\033[1m";
					}
					echo ltrim( str_replace( PHP_EOL, ' ', substr( $history_files[ $last_history_file ][0], 0, 100 ) ), '> ' );
					if ( $ansi ) {
						echo "\033[0m";
						echo PHP_EOL;
						echo str_repeat( ' ', strlen( $c . ') ' ) );
					} else {
						echo ' (';
					}
				}
				echo $ago, $used_model, $answers, ' answer', $answers === 1 ? '' : 's', ', ', str_word_count( $conversation_contents ), ' words';
				if ( ! $ansi ) {
					echo ')';
				}
				echo PHP_EOL;

				$last_conversations[ $c ] = $last_history_file;
				if ( isset( $options['l'] ) ) {
					break;
				}
			}

			krsort( $history_files );
			if ( $c < $options['r'] ) {
				continue;
			}
		}
		echo PHP_EOL;
		if ( isset( $options['l'] ) ) {
			$sel = 1;
			break;
		}

		if ( 1 === count( $last_history_files ) ) {
			echo 'Resume this conversation (m for more): ';
		} else {
			echo 'Please enter the number of the conversation you want to resume (m for more): ';
		}
		$sel = readline();
		if ( 1 === count( $last_history_files ) ) {
			if ( $sel < 0 || 'y' === $sel ) {
				$sel = 1;
			} else {
				$sel = 'm';
			}
		}
	}
	if ( $sel ) {
		if ( ! isset( $last_conversations[ $sel ] ) ) {
			echo 'Invalid selection.', PHP_EOL;
		}
		if ( substr( $history_files[ $last_conversations[ $sel ] ][ 0 ], 0, 7 ) === 'System:' ) {
			$system = substr( $history_files[ $last_conversations[ $sel ] ][ 0 ], 8, strpos( $history_files[ $last_conversations[ $sel ] ][ 0 ], PHP_EOL ) - 8 );
			$history_files[ $last_conversations[ $sel ] ][ 0 ]	= substr( $history_files[ $last_conversations[ $sel ] ][ 0 ], strlen( $system ) + 9 );
			if ( isset( $options['s'] ) && $options['s'] ) {
				echo 'Old System prompt: ' . $system, PHP_EOL, 'New ';
				$system = $options['s'];
			}
			echo 'System prompt: ', $system, PHP_EOL;
			array_unshift( $messages, array(
				'role'    => 'system',
				'content' => $system,
			) );
		}
		foreach ( $history_files[ $last_conversations[ $sel ] ] as $k => $message ) {
			if ( isset( $options['d'] ) && $k % 2 ) {
				// Ignore assistant answers.
				continue;
			}

			$messages[] = array(
				'role'    => $k % 2 ? 'assistant' : 'user',
				'content' => $message,
			);

			if ( 0 === $k % 2 ) {
				echo '> ';
			}
			output_message( $message );
			echo PHP_EOL;
		}
		if ( isset( $options['d'] ) ) {
			$initial_input = ' ';
			// Answer the question right away.
		}

	}
} elseif ( ! empty( $options['s'] ) || isset( $options['f'] ) ) {
	$system = '';
	if ( isset( $options['f'] ) ) {
		$system = 'When recommending file content it must be prepended with the proposed filename in the form: "File: filename.ext"';
	}
	if ( ! empty( $options['s'] ) ) {
		if ( $system ) {
			$system .= ' ';
		}
		$system .= $options['s'];
	}
	if ( $system ) {
		array_unshift( $messages, array(
			'role'    => 'system',
			'content' => $system,
		) );
		if ( ! $stdin || isset( $options['v'] ) ) {
			echo 'System prompt: ', $system, PHP_EOL;
		}
	}
	if ( trim( $initial_input ) ) {
		if ( ! $stdin || isset( $options['v'] ) ) {
			echo '> ', $initial_input, PHP_EOL;
		}
	}
} elseif ( trim( $initial_input ) ) {
	if ( ! $stdin || isset( $options['v'] ) ) {
		echo '> ', $initial_input, PHP_EOL;
	}
}

readline_clear_history();
readline_read_history( $readline_history_file );

$headers = array(
	'Content-Type: application/json',
	'Transfer-Encoding: chunked',
);
if ( 'OpenAI' === $supported_models[$model] ) {
	curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
	$headers[] = 'Authorization: Bearer ' . $openai_key;
} elseif ( 'Ollama (local)' === $supported_models[$model] ) {
	curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/v1/chat/completions' );
}

$chunk_overflow = '';
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
curl_setopt(
	$ch,
	CURLOPT_WRITEFUNCTION,
	function ( $curl, $data ) use ( &$message, &$chunk_overflow, &$state ) {
		if ( 200 !== curl_getinfo( $curl, CURLINFO_HTTP_CODE ) ) {
			$error = json_decode( trim( $chunk_overflow . $data ), true );
			if ( $error ) {
				echo 'Error: ', $error['error']['message'], PHP_EOL;
			} else {
				$chunk_overflow .= $data;
			}
			return strlen( $data );
		}
		$items = explode( 'data: ', $data );
		foreach ( $items as $item ) {
			if ( ! $item ) {
				continue;
			}
			$json = json_decode( trim( $chunk_overflow . $item ), true );
			if ( $json ) {
				$chunk_overflow = '';
			} else {
				$json = json_decode( trim( $item ), true );
			}
			if ( isset( $json['choices'][0]['delta']['content'] ) ) {
				output_message( $json['choices'][0]['delta']['content'] );

				$message .= $json['choices'][0]['delta']['content'];
			} else {
				$chunk_overflow = $item;
			}
		}

		return strlen( $data );
	}
);

// Start chatting.
$multiline = false;
while ( true ) {
	if ( ! empty( $initial_input ) ) {
		$input = $initial_input;
		$initial_input = null;
	} else {
		$input = readline( '> ' );
	}
	if ( false !== $multiline ) {
		if ( '.' !== trim( $input ) ) {
			$multiline .= $input . PHP_EOL;
			continue;
		} else {
			$input = rtrim( $multiline ) . PHP_EOL;
			// Finished with Multiline input.
			$multiline = false;
		}
	}

	if ( false === $input || in_array( strtolower( trim( $input ) ), array( 'quit', 'exit', 'bye' ) ) ) {
		break;
	}

	if ( empty( $input ) || '.' === $input ) {
		$multiline = '';
		echo 'Starting multiline input. End with the last message as just a dot.', PHP_EOL;
		continue;
	}

	if ( ':' === substr( trim( $input ), -1 ) ) {
		$multiline = $input . PHP_EOL;
		echo 'Continuing multiline input. End with the last message as just a dot.', PHP_EOL;
		continue;
	}

	readline_add_history( $input );
	if ( ! $fp ) {
		if ( ! file_exists( $history_directory ) ) {
			mkdir( $history_directory, 0777, true );
		}

		if ( $sel && $last_conversations && isset( $last_conversations[ $sel ] ) ) {
			copy( $last_conversations[ $sel ], $full_history_file );

			if ( $system ) {
				file_put_contents( $full_history_file, 'System: ' . $system  . PHP_EOL . trim( preg_replace( '/^System: .*$/m', '', file_get_contents( $full_history_file ) ) ) );
				$system = false;
			}
		}

		$fp = fopen( $full_history_file, 'a' );
	}

	if ( isset( $options['i'] ) ) {
		$files = explode( ' ', $options['i'] );
		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				echo 'Local File: ', $file, ' (', filesize( $file ), ' bytes):', PHP_EOL;
				// show the first 5 lines:
				echo "\033[90m";
				$if = fopen( $file, 'r' );
				for ( $i = 0; $i < 5; $i++ ) {
					$line = fgets( $if );
					if ( false === $line ) {
						break;
					}
					echo $line;
				}
				fclose( $if );
				echo "\033[m";
				echo 'Add file content to the prompt? [y/N]: ';

				$add = readline();
				if ( 'y' !== strtolower( $add ) ) {
					echo 'Skipping file: ', $file, PHP_EOL;
					continue;
				}
				$input .= PHP_EOL . 'File: `' . $file . '`' . PHP_EOL . '```' . str_replace( '```', '\`\`\`', file_get_contents( $file ) ) . '```';
			} else {
				echo 'File not found: ', $file, PHP_EOL;
			}
		}
		unset( $options['i'] );
	}

	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		readline_write_history( $readline_history_file );
		if ( $system ) {
			fwrite( $fp, 'System: ' . $system . PHP_EOL );
			$system = false;
		}
		if ( false === strpos( $input, PHP_EOL ) ) {
			fwrite( $fp, '> ' . $input . PHP_EOL . PHP_EOL );
		} else {
			fwrite( $fp, '>>> ' . $input . PHP_EOL . '.' . PHP_EOL . PHP_EOL );
		}
	}

	$image = false;
	if ( isset( $options['p'] ) ) {
		if ( preg_match( '/^(gpt-4o|llava)/', $model ) ) {
			$image = trim( $options['p'] );
			if ( ! filter_var( $image, FILTER_VALIDATE_URL ) ) {
				if ( ! file_exists( $image ) ) {
					echo 'Image file not found: ', $image, PHP_EOL;
					exit(1);
				} else {
					$mime = mime_content_type( $image );
					$image = 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $image ) );
				}
			}
		} else {
			echo 'Image input is only supported with gpt-4o* or llava.', PHP_EOL;
			exit(1);
		}
	}

	if ( $image ) {
		$input = array(
			array(
				'type' => 'text',
				'text' => $input,
			),
			array(
				'type' => 'image_url',
				'image_url' => array(
					'url' => $image,
				),
			),
		);
		// Only send the image in the first message.
		unset( $options['p'] );
	}

	$messages[] = array(
		'role'    => 'user',
		'content' => $input,
	);

	curl_setopt(
		$ch,
		CURLOPT_POSTFIELDS,
		json_encode(
			array(
				'model'        => $model,
				'messages'     => $messages,
				'stream'       => true,
			)
		)
	);

	if ( ! $stdin || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$message = '';

	$output = curl_exec( $ch );
	if ( curl_error( $ch ) ) {
		echo 'CURL Error: ', curl_error( $ch ), PHP_EOL;
		exit( 1 );
	}
	if ( ! $stdin || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$messages[] = array(
		'role'    => 'assistant',
		'content' => $message,
	);
	if ( ! is_string( $input ) ) {
		$input = $input[0]['text'];
	}
	if ( isset( $options['f'] ) ) {
		preg_match_all( '/^(?:#+\s*)?File: `?([a-z0-9_.-]+)`?$/m', $message, $matches, PREG_SET_ORDER );
		if ( $matches ) {
			foreach ( $matches as $match ) {
				$file = $match[1];
				preg_match( '/^' . preg_quote( $match[0], '/' ) . '.*?```[a-z0-9_-]*\n(.*?)```/sm', $message, $m );
				if ( $m ) {
					if ( file_exists( $file ) ) {
						$backup_filename = $file . '.bak.' . time();
						echo "\033[33m";
						echo 'Backing up existing file: ', $file, ' => ', $backup_filename, PHP_EOL;
						echo "\033[0m";

						copy( $file, $backup_filename );
					}
					echo "\033[32m";
					echo 'Writing ', strlen( $m[1] ), ' bytes to file: ', $file, PHP_EOL;
					echo "\033[0m";
					file_put_contents( $file, $m[1] );
				}
			}
		}
	}
	if ( $stdin || ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace or coming from stdin.
		fwrite( $fp, $message . PHP_EOL . PHP_EOL );
	}

	if ( isset( $options['v'] ) ) {
		output_message( '---OUTPUTOKENS' );
	}

	if ( $stdin ) {
		break;
	}
}
if ( $fp ) {
	fclose( $fp );
}
