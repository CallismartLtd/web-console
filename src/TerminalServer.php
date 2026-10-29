<?php
declare( strict_types=1 );

require_once __DIR__ . '/TerminalSession.php';

/**
 * HTTP controller and PTY lifecycle for the PHP Web Terminal.
 * Requires Linux/Unix, PHP POSIX support, and util-linux `script`.
 */
final class TerminalServer {
	private TerminalSession $session;
	private string $password;
	private bool $allowRemote;

	public function __construct() {
		$this->session = new TerminalSession();
		$this->password = (string) ( getenv( 'CONSOLE_PASSWORD' ) ?: '' );
		$this->allowRemote = '1' === getenv( 'CONSOLE_ALLOW_REMOTE' );
	}

	public function run() : void {
		$this->bootstrapSession();
		$remote = ! in_array( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), ['127.0.0.1', '::1'], true );
		if ( $remote && ( ! $this->allowRemote || '' === $this->password ) ) {
			http_response_code( 403 );
			echo 'Forbidden: remote access requires CONSOLE_ALLOW_REMOTE=1 and CONSOLE_PASSWORD.';
			return;
		}

		if ( '' !== $this->password && ! $this->session->get( 'authenticated', false ) ) {
			if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['login_password'] ) ) {
				if ( hash_equals( $this->password, (string) $_POST['login_password'] ) ) {
					$this->session->regenerateId( true );
					$this->session->set( 'authenticated', true );
					header( 'Location: ' . strtok( (string) $_SERVER['REQUEST_URI'], '?' ) );
					exit;
				}
				$this->renderLogin( 'Incorrect password.' );
				return;
			}
			$this->renderLogin();
			return;
		}

		if ( ! $this->session->has( 'csrf' ) ) {
			$this->session->set( 'csrf', bin2hex( random_bytes( 24 ) ) );
		}

		$action = (string) ( $_POST['action'] ?? $_GET['action'] ?? '' );
		if ( '' !== $action ) {
			$this->dispatch( $action );
			return;
		}

		$csrf = (string) $this->session->get( 'csrf' );
		$this->session->close();
		$this->renderApp( $csrf );
	}

	private function bootstrapSession() : void {
		$this->session->start();
	}

	private function dispatch( string $action ) : void {
		if ( '' !== $this->password && ! $this->session->get( 'authenticated', false ) ) {
			$this->json( ['error' => 'Authentication required.'], 401 );
		}
		$token = (string) ( $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['token'] ?? '' );
		if ( ! $this->session->has( 'csrf' ) || ! hash_equals( (string) $this->session->get( 'csrf' ), $token ) ) {
			$this->json( ['error' => 'Invalid CSRF token. Reload the page.'], 403 );
		}

		switch ( $action ) {
			case 'start': $this->startPty();
			case 'input': $this->writeInput();
			case 'upload': $this->uploadFile();
			case 'read': $this->readOutput();
			case 'resize': $this->resizePty();
			case 'close': $this->closePty();
			case 'logout': $this->logout();
			default: $this->json( ['error' => 'Unknown action.'], 404 );
		}
	}

	private function startPty() : never {
		if ( PHP_OS_FAMILY === 'Windows' || ! function_exists( 'posix_mkfifo' ) ) {
			$this->json( ['error' => 'PTY support requires Linux/Unix and the PHP POSIX extension.'], 501 );
		}
		$script = trim( (string) shell_exec( 'command -v script 2>/dev/null' ) );
		if ( '' === $script ) {
			$this->json( ['error' => 'The util-linux `script` command is required.'], 501 );
		}

		$oldId = (string) $this->session->get( 'pty_id', '' );
		$oldPid = (int) $this->session->get( 'pty_pid', 0 );
		if ( '' !== $oldId ) {
			$this->cleanupPty( $oldId, $oldPid );
		}

		$id = bin2hex( random_bytes( 16 ) );
		$paths = $this->paths( $id );
		if ( ! @posix_mkfifo( $paths['input'], 0600 ) ) {
			$this->json( ['error' => 'Could not create terminal input FIFO.'], 500 );
		}
		@chmod( $paths['input'], 0600 );
		if ( false === @file_put_contents( $paths['output'], '' ) ) {
			@unlink( $paths['input'] );
			$this->json( ['error' => 'Could not create terminal output log.'], 500 );
		}
		@chmod( $paths['output'], 0600 );

		$rows = max( 5, min( 100, (int) ( $_POST['rows'] ?? 24 ) ) );
		$cols = max( 20, min( 300, (int) ( $_POST['cols'] ?? 80 ) ) );
		$shell = (string) ( getenv( 'SHELL' ) ?: '/bin/bash' );
		if ( ! is_executable( $shell ) ) $shell = '/bin/sh';
		$host = (string) ( gethostname() ?: 'localhost' );
		$prompt = '\\u@\\h:\\w\\$ ';
		$command = 'stty rows ' . $rows . ' cols ' . $cols . ' 2>/dev/null; '
			. 'export TERM=xterm-256color COLORTERM=truecolor PS1=' . escapeshellarg( $prompt ) . '; '
			. 'unset PROMPT_COMMAND; '
			. ( str_contains( basename( $shell ), 'bash' )
				? 'exec ' . escapeshellarg( $shell ) . ' --noprofile --norc -i'
				: 'exec ' . escapeshellarg( $shell ) . ' -i' );

		// The shell is the only component allowed to emit a prompt. The browser
		// receives PTY bytes verbatim; there is no JavaScript prompt renderer.
		$runner = 'exec 3<> "$1"; exec ' . escapeshellarg( $script )
			. ' -q -f -e -c "$2" /dev/null <&3 >> "$3"';
		$launch = 'nohup sh -c ' . escapeshellarg( $runner ) . ' sh '
			. escapeshellarg( $paths['input'] ) . ' ' . escapeshellarg( $command ) . ' '
			. escapeshellarg( $paths['output'] ) . ' 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- >/dev/null 2>&1 < /dev/null & echo $!';
		$pid = (int) trim( (string) shell_exec( $launch ) );
		if ( $pid < 2 ) {
			$this->cleanupPty( $id, 0 );
			$this->json( ['error' => 'Failed to start the shell process.'], 500 );
		}

		$this->session->set( 'pty_id', $id );
		$this->session->set( 'pty_pid', $pid );
		$this->session->close();
		usleep( 120000 );
		$this->json( ['ok' => true, 'offset' => 0] );
	}

	private function writeInput() : never {
		$id = (string) $this->session->get( 'pty_id', '' );
		$pid = (int) $this->session->get( 'pty_pid', 0 );
		$data = (string) ( $_POST['data'] ?? '' );
		$this->session->close();
		if ( '' === $id || ! $this->alive( $pid ) ) $this->json( ['error' => 'The shell is not running.'], 410 );
		if ( strlen( $data ) > 16384 ) $this->json( ['error' => 'Input chunk is too large.'], 413 );
		if ( '' !== $data ) {
			$pipe = @fopen( $this->paths( $id )['input'], 'wb' );
			if ( false === $pipe ) $this->json( ['error' => 'Could not open the terminal input pipe.'], 500 );
			$offset = 0;
			while ( $offset < strlen( $data ) ) {
				$n = @fwrite( $pipe, substr( $data, $offset ) );
				if ( false === $n || 0 === $n ) break;
				$offset += $n;
			}
			fclose( $pipe );
			if ( $offset !== strlen( $data ) ) $this->json( ['error' => 'Input was only partially written.'], 500 );
		}
		$this->json( ['ok' => true] );
	}

	/** Upload a file into the interactive shell's current working directory. */
	private function uploadFile() : never {
		$id = (string) $this->session->get( 'pty_id', '' );
		$pid = (int) $this->session->get( 'pty_pid', 0 );
		if ( '' === $id || ! $this->alive( $pid ) ) {
			$this->session->close();
			$this->json( ['error' => 'The shell is not running. Reconnect before uploading.'], 410 );
		}

		if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			$this->session->close();
			$this->json( ['error' => 'Choose a file to upload.'], 400 );
		}
		$upload = $_FILES['file'];
		$uploadError = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $uploadError ) {
			$this->session->close();
			$message = match ( $uploadError ) {
				UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit.',
				UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
				UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
				default => 'The upload failed (code ' . $uploadError . ').',
			};
			$this->json( ['error' => $message], 400 );
		}
		$tmp = (string) ( $upload['tmp_name'] ?? '' );
		$size = (int) ( $upload['size'] ?? 0 );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->session->close();
			$this->json( ['error' => 'Invalid uploaded file.'], 400 );
		}
		if ( $size > 20 * 1024 * 1024 ) {
			$this->session->close();
			$this->json( ['error' => 'Files must be 20 MB or smaller.'], 413 );
		}

		// The `script` process owns the PTY and its child is the interactive shell.
		// Resolve the shell's actual cwd so uploads follow `cd` commands.
		$child = trim( (string) @shell_exec( 'pgrep -P ' . $pid . ' 2>/dev/null | head -n 1' ) );
		if ( '' === $child || ! ctype_digit( $child ) ) {
			$this->session->close();
			$this->json( ['error' => 'Could not locate the interactive shell process.'], 503 );
		}
		$directory = @realpath( '/proc/' . $child . '/cwd' );
		if ( false === $directory || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			$this->session->close();
			$this->json( ['error' => 'The shell working directory is not writable by the web-server user.'], 403 );
		}

		$originalName = str_replace( "\\", '/', (string) ( $upload['name'] ?? 'upload.bin' ) );
		$name = basename( $originalName );
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '_', $name ) ?? '';
		if ( '' === $name || '.' === $name || '..' === $name ) {
			$this->session->close();
			$this->json( ['error' => 'Invalid file name.'], 400 );
		}
		$destination = $directory . DIRECTORY_SEPARATOR . $name;
		if ( is_dir( $destination ) ) {
			$this->session->close();
			$this->json( ['error' => 'A directory with that name already exists.'], 409 );
		}
		if ( ! @move_uploaded_file( $tmp, $destination ) ) {
			$this->session->close();
			$this->json( ['error' => 'Could not save the file in the shell working directory.'], 500 );
		}
		@chmod( $destination, 0644 );
		$this->session->close();
		$this->json( ['ok' => true, 'name' => $name, 'directory' => $directory, 'size' => $size] );
	}

	private function readOutput() : never {
		$id = (string) $this->session->get( 'pty_id', '' );
		$pid = (int) $this->session->get( 'pty_pid', 0 );
		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		$this->session->close();
		if ( '' === $id ) $this->json( ['error' => 'No terminal session.'], 409 );
		$file = $this->paths( $id )['output'];
		clearstatcache( true, $file );
		$data = '';
		$size = @filesize( $file );
		if ( false !== $size && $offset > $size ) $offset = $size;
		$fh = @fopen( $file, 'rb' );
		if ( false !== $fh ) {
			if ( 0 === fseek( $fh, $offset ) ) {
				$data = (string) fread( $fh, 65536 );
				$offset = ftell( $fh );
			}
			fclose( $fh );
		}
		$this->json( ['ok' => true, 'data' => base64_encode( $data ), 'offset' => $offset, 'alive' => $this->alive( $pid )] );
	}

	private function resizePty() : never {
		$id = (string) $this->session->get( 'pty_id', '' );
		$pid = (int) $this->session->get( 'pty_pid', 0 );
		$rows = max( 5, min( 100, (int) ( $_POST['rows'] ?? 24 ) ) );
		$cols = max( 20, min( 300, (int) ( $_POST['cols'] ?? 80 ) ) );
		$this->session->close();
		if ( '' === $id || ! $this->alive( $pid ) ) $this->json( ['error' => 'The shell is not running.'], 410 );
		// `script` owns the PTY master; its interactive shell child owns the slave.
		// Set the window size through that child's fd 0 when procfs is available.
		$child = trim( (string) @shell_exec( 'pgrep -P ' . $pid . ' 2>/dev/null | head -n 1' ) );
		if ( '' !== $child && ctype_digit( $child ) ) {
			@shell_exec( 'stty -F ' . escapeshellarg( '/proc/' . $child . '/fd/0' ) . ' rows ' . $rows . ' cols ' . $cols . ' 2>/dev/null' );
			@posix_kill( (int) $child, self::signalNumber( 'SIGWINCH', 28 ) );
		}
		$this->json( ['ok' => true] );
	}

	private function closePty() : never {
		$id = (string) $this->session->get( 'pty_id', '' );
		$pid = (int) $this->session->get( 'pty_pid', 0 );
		$this->session->remove( 'pty_id', 'pty_pid' );
		$this->session->close();
		$this->cleanupPty( $id, $pid );
		$this->json( ['ok' => true] );
	}

	private function logout() : never {
		$id = (string) $this->session->get( 'pty_id', '' );
		$pid = (int) $this->session->get( 'pty_pid', 0 );
		$this->session->clearData();
		$this->session->close();
		$this->cleanupPty( $id, $pid );
		$this->session->destroy();
		$this->json( ['ok' => true] );
	}

	private function paths( string $id ) : array {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR
			. 'wcpty_' . hash( 'sha256', $this->session->id() . '|' . $id );
		return ['input' => $base . '.in', 'output' => $base . '.out'];
	}

	private function alive( int $pid ) : bool {
		if ( $pid <= 1 || ! function_exists( 'posix_kill' ) || ! @posix_kill( $pid, 0 ) ) return false;
		// kill(pid, 0) also succeeds for a zombie. Treat exited script(1)
		// processes as dead so `exit` updates the browser connection state.
		$stat = @file_get_contents( '/proc/' . $pid . '/stat' );
		if ( is_string( $stat ) ) {
			$end = strrpos( $stat, ')' );
			if ( false !== $end ) {
				$state = trim( substr( $stat, $end + 1 ) );
				$state = $state[0] ?? '';
				if ( 'Z' === $state || 'X' === $state ) return false;
			}
		}
		return true;
	}

	/**
	 * Resolve POSIX signal numbers without assuming the pcntl extension is
	 * loaded. Signal constants are extension-provided on some PHP builds and
	 * may be absent under Apache/FPM even when the POSIX functions exist.
	 * Numeric fallbacks below target Linux, which is the supported PTY host.
	 */
	private static function signalNumber( string $name, int $linuxFallback ) : int {
		if ( defined( $name ) ) {
			return (int) constant( $name );
		}

		if ( PHP_OS_FAMILY !== 'Linux' ) {
			throw new RuntimeException( 'Signal constant ' . $name . ' is unavailable on this platform.' );
		}

		return $linuxFallback;
	}

	private function cleanupPty( string $id, int $pid ) : void {
		if ( '' === $id ) return;
		if ( $pid > 1 && function_exists( 'posix_kill' ) ) {
			$children = preg_split( '/\s+/', trim( (string) @shell_exec( 'pgrep -P ' . $pid . ' 2>/dev/null' ) ) );
			foreach ( $children ?: [] as $child ) {
				if ( ctype_digit( $child ) && (int) $child > 1 ) @posix_kill( (int) $child, self::signalNumber( 'SIGTERM', 15 ) );
			}
			@posix_kill( $pid, self::signalNumber( 'SIGTERM', 15 ) );
			usleep( 50000 );
			@posix_kill( $pid, self::signalNumber( 'SIGKILL', 9 ) );
		}
		$paths = $this->paths( $id );
		@unlink( $paths['input'] );
		@unlink( $paths['output'] );
	}

	private function json( array $data, int $status = 200 ) : never {
		http_response_code( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function renderLogin( string $error = '' ) : void {
		$message = htmlspecialchars( $error, ENT_QUOTES, 'UTF-8' );
		echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Terminal login</title><link rel="stylesheet" href="assets/terminal.css"><body class="login-page"><form method="post" class="login-card"><h1>PHP Web Terminal</h1><p>Authenticate to continue.</p>';
		if ( '' !== $message ) echo '<p class="error">' . $message . '</p>';
		echo '<input type="password" name="login_password" placeholder="Password" required autofocus><button type="submit">Connect</button></form></body></html>';
	}

	private function renderApp( string $csrf ) : void {
		$user = (string) ( getenv( 'USER' ) ?: get_current_user() );
		$host = (string) ( gethostname() ?: 'localhost' );
		$cwd = (string) getcwd();
		$home = (string) ( getenv( 'HOME' ) ?: '' );
		$config = json_encode( ['csrf' => $csrf, 'user' => $user, 'host' => $host, 'cwd' => $cwd, 'home' => $home], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PHP Web Terminal</title><link rel="stylesheet" href="assets/terminal.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css"></head><body>';
		echo '<header class="toolbar"><div class="brand"><span class="brand-mark">›_</span><div><strong>PHP Web Terminal</strong><small id="host-label"></small></div></div><div class="toolbar-actions"><span class="status"><i id="status-dot"></i><span id="status-label">Connecting</span></span><button id="btn-connect" class="button" hidden>Reconnect</button><button id="btn-disconnect" class="button">Disconnect</button><button id="btn-logout" class="button danger">Logout</button><button id="btn-upload" class="button">Upload file</button><input id="file-upload" type="file" hidden><button id="btn-clear" class="button">Clear</button><button id="btn-smaller" class="button square" title="Decrease font size">A−</button><button id="btn-larger" class="button square" title="Increase font size">A+</button></div></header>';
		echo '<main class="terminal-shell"><div id="terminal" aria-label="Interactive terminal"></div><div id="terminal-overlay" class="terminal-overlay" hidden><div class="overlay-card"><strong>Terminal disconnected</strong><p>The shell has stopped or the terminal view was disconnected.</p><button id="overlay-reconnect" class="button primary">Reconnect</button></div></div></main><footer class="footer"><span>Interactive shell · PTY-backed</span><span id="cwd-label"></span></footer>';
		echo '<script id="terminal-config" type="application/json">' . $config . '</script><script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script><script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script><script src="assets/terminal.js" defer></script></body></html>';
	}
}
