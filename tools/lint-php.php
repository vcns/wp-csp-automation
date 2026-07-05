<?php
/**
 * Portable PHP syntax lint runner.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$excluded_dirs = array(
	'.git',
	'.phpunit.cache',
	'node_modules',
	'vendor',
);

$files       = array();
$git_command = 'git -C ' . escapeshellarg( $root ) . ' ls-files ' . escapeshellarg( '*.php' );
exec( $git_command, $tracked_files, $git_exit_code );

if ( 0 === $git_exit_code ) {
	foreach ( $tracked_files as $tracked_file ) {
		$parts = explode( '/', $tracked_file );
		if ( ! empty( array_intersect( $parts, $excluded_dirs ) ) ) {
			continue;
		}

		$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $tracked_file );
		if ( is_file( $path ) ) {
			$files[] = $path;
		}
	}
}

if ( empty( $files ) ) {
	$directory = new RecursiveDirectoryIterator(
		$root,
		FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
	);

	$filter = new RecursiveCallbackFilterIterator(
		$directory,
		static function ( SplFileInfo $current ) use ( $excluded_dirs ): bool {
			if ( ! $current->isDir() ) {
				return true;
			}

			return ! in_array( $current->getFilename(), $excluded_dirs, true );
		}
	);

	$iterator = new RecursiveIteratorIterator( $filter );

	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

$failed = false;
$count  = 0;

foreach ( $files as $path ) {
	++$count;
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path );
	echo "Linting {$path}" . PHP_EOL;
	passthru( $command, $exit_code );

	if ( 0 !== $exit_code ) {
		$failed = true;
	}
}

if ( 0 === $count ) {
	echo 'No PHP files found.' . PHP_EOL;
	exit( 0 );
}

if ( $failed ) {
	fwrite( STDERR, 'PHP lint failures detected.' . PHP_EOL );
	exit( 1 );
}

echo 'PHP lint passed.' . PHP_EOL;
