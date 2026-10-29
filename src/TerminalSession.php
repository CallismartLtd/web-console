<?php
declare( strict_types=1 );

/**
 * Owns PHP session configuration, storage, lifecycle, and session data access.
 * The session directory is private to the effective PHP worker user by default.
 */
final class TerminalSession {
	private const DEFAULT_NAME = 'WCTERMINAL';

	private string $name;
	private bool $started = false;

	public function __construct( ?string $name = null ) {
		$this->name = null !== $name && '' !== $name ? $name : self::DEFAULT_NAME;
	}

	/** Start the session using a save directory writable by this PHP process. */
	public function start() : void {
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			$this->started = true;
			return;
		}
		if ( headers_sent( $file, $line ) ) {
			throw new RuntimeException( "Cannot start session: headers already sent at {$file}:{$line}." );
		}

		$directory = $this->resolveSavePath();
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			throw new RuntimeException( 'PHP session directory is not writable by the current PHP worker: ' . $directory );
		}

		if ( session_name() !== $this->name ) {
			session_name( $this->name );
			if ( session_name() !== $this->name ) {
				throw new RuntimeException( 'Could not set the PHP session name.' );
			}
		}
		if ( session_save_path() !== $directory ) {
			session_save_path( $directory );
			if ( session_save_path() !== $directory ) {
				throw new RuntimeException( 'Could not set the PHP session save path.' );
			}
		}

		$https = isset( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] && 'off' !== strtolower( (string) $_SERVER['HTTPS'] );
		if ( ! session_set_cookie_params( [
			'httponly' => true,
			'samesite' => 'Strict',
			'secure' => $https,
			'path' => '/',
		] ) ) {
			throw new RuntimeException( 'Could not configure the PHP session cookie.' );
		}

		if ( ! session_start() ) {
			throw new RuntimeException( 'PHP could not start the session. Check the session directory permissions and PHP error log.' );
		}
		$this->started = true;
	}

	/** Resolve a stable per-worker-user directory, or validate an explicit override. */
	private function resolveSavePath() : string {
		$configured = trim( (string) ( getenv( 'CONSOLE_SESSION_PATH' ) ?: '' ) );
		if ( '' !== $configured ) {
			$directory = rtrim( $configured, DIRECTORY_SEPARATOR );
			if ( '' === $directory ) $directory = DIRECTORY_SEPARATOR;
			if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
				throw new RuntimeException( 'Could not create configured session directory: ' . $directory );
			}
			if ( ! is_writable( $directory ) ) {
				throw new RuntimeException( 'Configured session directory is not writable by the current PHP worker: ' . $directory );
			}
			return $directory;
		}

		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR );
		if ( '' === $base || ! is_dir( $base ) || ! is_writable( $base ) ) {
			throw new RuntimeException( 'The system temporary directory is not writable by the current PHP worker. Set CONSOLE_SESSION_PATH to a writable directory.' );
		}

		$uid = function_exists( 'posix_geteuid' ) ? (string) posix_geteuid() : 'uid-' . (string) getmyuid();
		$directory = $base . DIRECTORY_SEPARATOR . 'wc_terminal_sessions_' . preg_replace( '/[^a-zA-Z0-9_-]/', '_', $uid );
		if ( ! is_dir( $directory ) ) {
			$oldUmask = umask( 0077 );
			try {
				if ( ! @mkdir( $directory, 0700 ) && ! is_dir( $directory ) ) {
					throw new RuntimeException( 'Could not create private session directory: ' . $directory . '. Set CONSOLE_SESSION_PATH to a writable directory.' );
				}
			} finally {
				umask( $oldUmask );
			}
			@chmod( $directory, 0700 );
		}

		$real = realpath( $directory );
		if ( false === $real || ! is_dir( $real ) || ! is_writable( $real ) ) {
			throw new RuntimeException( 'Private session directory is not writable by the current PHP worker: ' . $directory );
		}
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'fileowner' ) && @fileowner( $real ) !== posix_geteuid() ) {
			throw new RuntimeException( 'Private session directory is not owned by the current PHP worker user: ' . $real . '. Set CONSOLE_SESSION_PATH to a directory owned by that user.' );
		}
		@chmod( $real, 0700 );
		return $real;
	}

	public function has( string $key ) : bool {
		$this->assertActive();
		return array_key_exists( $key, $_SESSION );
	}

	public function get( string $key, mixed $default = null ) : mixed {
		$this->assertActive();
		return $_SESSION[$key] ?? $default;
	}

	public function set( string $key, mixed $value ) : void {
		$this->assertActive();
		$_SESSION[$key] = $value;
	}

	public function remove( string ...$keys ) : void {
		$this->assertActive();
		foreach ( $keys as $key ) unset( $_SESSION[$key] );
	}

	public function clearData() : void {
		$this->assertActive();
		$_SESSION = [];
	}

	public function regenerateId( bool $deleteOld = true ) : void {
		$this->assertActive();
		if ( ! session_regenerate_id( $deleteOld ) ) {
			throw new RuntimeException( 'Could not regenerate the session ID.' );
		}
	}

	public function id() : string {
		$id = session_id();
		if ( '' === $id ) {
			throw new LogicException( 'No PHP session ID is available.' );
		}
		return $id;
	}

	public function close() : void {
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			if ( ! session_write_close() ) {
				throw new RuntimeException( 'Could not persist and close the PHP session.' );
			}
		}
		$this->started = false;
	}

	/** Clear data, destroy server-side state, and expire the session cookie. */
	public function destroy() : void {
		if ( PHP_SESSION_ACTIVE !== session_status() ) {
			$this->start();
		}
		$_SESSION = [];
		$params = session_get_cookie_params();
		$cookieName = session_name();
		if ( ! session_destroy() ) {
			throw new RuntimeException( 'Could not destroy the PHP session.' );
		}
		if ( ! headers_sent() ) {
			setcookie( $cookieName, '', [
				'expires' => time() - 3600,
				'path' => $params['path'] ?: '/',
				'domain' => $params['domain'],
				'secure' => $params['secure'],
				'httponly' => $params['httponly'],
				'samesite' => $params['samesite'] ?? 'Strict',
			] );
		}
		$this->started = false;
	}

	private function assertActive() : void {
		if ( ! $this->started || PHP_SESSION_ACTIVE !== session_status() ) {
			throw new LogicException( 'Session data accessed while the session is not active. Call start() first.' );
		}
	}
}
