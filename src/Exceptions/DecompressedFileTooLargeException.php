<?php

namespace Opcodes\LogViewer\Exceptions;

/**
 * A compressed log file decompresses to more than
 * `log-viewer.max_decompressed_file_size_in_mb` allows.
 */
class DecompressedFileTooLargeException extends CannotOpenFileException {}
