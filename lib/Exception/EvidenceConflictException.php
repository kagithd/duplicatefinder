<?php
namespace OCA\DuplicateFinder\Exception;

/** The file observed by the checker no longer matches the server-side reference. */
class EvidenceConflictException extends \RuntimeException
{
}
