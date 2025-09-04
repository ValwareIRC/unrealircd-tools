<?php
/**
 * UnrealIRCd Configuration Parser with Debug Logging
 */

class UnrealConfigParser {
    private $filename;
    private $lineNumber = 1;
    private $errors = [];
    private $warnings = [];
    private $debug = false;
    
    public function __construct($debug = false) {
        $this->debug = $debug;
    }
    
    private function debugLog($message) {
        if ($this->debug) {
            echo "[DEBUG] Line {$this->lineNumber}: $message\n";
        }
    }
    
    /**
     * Parse configuration file
     */
    public function parseFile($filename) {
        $this->debugLog("Starting to parse file: $filename");
        
        if (!file_exists($filename)) {
            throw new Exception("Configuration file not found: $filename");
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            throw new Exception("Could not read configuration file: $filename");
        }
        
        $this->debugLog("File read successfully, length: " . strlen($content));
        $this->filename = $filename;
        return $this->parseContent($content);
    }
    
    /**
     * Parse configuration content
     */
    public function parseContent($content) {
        $this->debugLog("Starting content parsing");
        
        // Replace \r with spaces (like the C code does)
        $content = str_replace("\r", " ", $content);
        
        $entries = [];
        $this->lineNumber = 1;
        $pos = 0;
        $length = strlen($content);
        
        $this->debugLog("Content length: $length");
        
        $blockCount = 0;
        while ($pos < $length) {
            $this->debugLog("Parsing block #$blockCount at position $pos");
            
            $result = $this->parseBlock($content, $pos, $length);
            if ($result) {
                $this->debugLog("Successfully parsed block: {$result->name}");
                $entries[] = $result;
                $blockCount++;
            } else {
                $this->debugLog("No block parsed at position $pos");
                // Prevent infinite loop
                if ($pos < $length) {
                    $this->debugLog("Advancing position to prevent infinite loop");
                    $pos++;
                }
            }
            
            // Safety check to prevent infinite loops
            if ($blockCount > 10000) {
                $this->addError("Too many blocks parsed, possible infinite loop");
                break;
            }
        }
        
        $this->debugLog("Finished parsing, found $blockCount blocks");
        
        return [
            'entries' => $entries,
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
    }
    
    /**
     * Parse a configuration block
     */
    private function parseBlock($content, &$pos, $length) {
        $startPos = $pos;
        $this->debugLog("parseBlock: starting at pos $pos");
        
        // Skip whitespace and comments
        $this->skipWhitespaceAndComments($content, $pos, $length);
        
        if ($pos >= $length) {
            $this->debugLog("parseBlock: reached end of content");
            return null;
        }
        
        $entry = new ConfigEntry();
        $entry->lineNumber = $this->lineNumber;
        $entry->filePositionStart = $pos;
        
        // Check if this is a quoted string (standalone value)
        if ($content[$pos] === '"' || $content[$pos] === "'") {
            $this->debugLog("parseBlock: found quoted string value");
            $value = $this->parseQuotedString($content, $pos, $length);
            if ($value !== null) {
                $entry->name = $value; // Store the quoted value as the name
                $entry->value = null;
                
                // Expect semicolon
                $this->skipWhitespace($content, $pos, $length);
                if ($pos < $length && $content[$pos] === ';') {
                    $pos++; // Skip ';'
                    $entry->filePositionEnd = $pos;
                    $this->debugLog("parseBlock: found semicolon after quoted value");
                } else {
                    $this->debugLog("parseBlock: no semicolon found after quoted value");
                    $this->addError("Missing semicolon after quoted value '$value' at line {$this->lineNumber}");
                }
                
                return $entry;
            }
            return null;
        }
        
        // Parse name
        $this->debugLog("parseBlock: parsing name at pos $pos");
        $name = $this->parseToken($content, $pos, $length);
        if (!$name) {
            $this->debugLog("parseBlock: no name found");
            return null;
        }
        
        $this->debugLog("parseBlock: found name '$name'");
        $entry->name = $name;
        
        // Skip whitespace
        $this->skipWhitespace($content, $pos, $length);
        
        // Check for value or block
        if ($pos < $length && $content[$pos] === '{') {
            $this->debugLog("parseBlock: found opening brace, parsing block items");
            // This is a block
            $pos++; // Skip '{'
            $entry->sectionLineNumber = $this->lineNumber;
            $entry->items = $this->parseItems($content, $pos, $length);
            
            // Expect closing '}'
            $this->skipWhitespaceAndComments($content, $pos, $length);
            if ($pos < $length && $content[$pos] === '}') {
                $this->debugLog("parseBlock: found closing brace");
                $pos++; // Skip '}'
                $entry->filePositionEnd = $pos;
                
                // Optional semicolon after }
                $this->skipWhitespace($content, $pos, $length);
                if ($pos < $length && $content[$pos] === ';') {
                    $pos++; // Skip ';'
                    $this->debugLog("parseBlock: found semicolon after closing brace");
                }
            } else {
                $this->addError("Missing closing brace for block '$name' at line {$this->lineNumber}");
            }
        } else {
            $this->debugLog("parseBlock: parsing value");
            // This might have a value
            $value = $this->parseValue($content, $pos, $length);
            if ($value !== null) {
                $this->debugLog("parseBlock: found value '$value'");
                $entry->value = $value;
                
                // After parsing the value, check if there's a block
                $this->skipWhitespace($content, $pos, $length);
                if ($pos < $length && $content[$pos] === '{') {
                    $this->debugLog("parseBlock: found opening brace after value, parsing block items");
                    // This is a block with a value (like "class clients")
                    $pos++; // Skip '{'
                    $entry->sectionLineNumber = $this->lineNumber;
                    $entry->items = $this->parseItems($content, $pos, $length);
                    
                    // Expect closing '}'
                    $this->skipWhitespaceAndComments($content, $pos, $length);
                    if ($pos < $length && $content[$pos] === '}') {
                        $this->debugLog("parseBlock: found closing brace");
                        $pos++; // Skip '}'
                        $entry->filePositionEnd = $pos;
                        
                        // Optional semicolon after }
                        $this->skipWhitespace($content, $pos, $length);
                        if ($pos < $length && $content[$pos] === ';') {
                            $pos++; // Skip ';'
                            $this->debugLog("parseBlock: found semicolon after closing brace");
                        }
                    } else {
                        $this->addError("Missing closing brace for block '$name $value' at line {$this->lineNumber}");
                    }
                    
                    return $entry;
                }
            }
            
            // Expect semicolon for simple directives
            $this->skipWhitespace($content, $pos, $length);
            if ($pos < $length && $content[$pos] === ';') {
                $pos++; // Skip ';'
                $entry->filePositionEnd = $pos;
                $this->debugLog("parseBlock: found semicolon");
            } else {
                $this->debugLog("parseBlock: no semicolon found at pos $pos, char: " . 
                    ($pos < $length ? "'" . $content[$pos] . "'" : "EOF"));
                $this->addError("Missing semicolon after directive '$name' at line {$this->lineNumber}");
            }
        }
        
        $this->debugLog("parseBlock: completed parsing '$name'");
        return $entry;
    }
    
    /**
     * Parse items within a block
     */
    private function parseItems($content, &$pos, $length) {
        $this->debugLog("parseItems: starting at pos $pos");
        $items = [];
        $itemCount = 0;
        
        while ($pos < $length) {
            $this->skipWhitespaceAndComments($content, $pos, $length);
            
            if ($pos >= $length || $content[$pos] === '}') {
                $this->debugLog("parseItems: found end or closing brace");
                break;
            }
            
            $this->debugLog("parseItems: parsing item #$itemCount");
            $item = $this->parseBlock($content, $pos, $length);
            if ($item) {
                $this->debugLog("parseItems: added item '{$item->name}'");
                $items[] = $item;
                $itemCount++;
            } else {
                $this->debugLog("parseItems: no item parsed, advancing position");
                // Prevent infinite loop
                $pos++;
            }
            
            // Safety check
            if ($itemCount > 1000) {
                $this->addError("Too many items in block, possible infinite loop");
                break;
            }
        }
        
        $this->debugLog("parseItems: completed, found $itemCount items");
        return $items;
    }
    
    /**
     * Parse a token (name or unquoted value)
     */
    private function parseToken($content, &$pos, $length) {
        $this->skipWhitespace($content, $pos, $length);
        
        if ($pos >= $length) {
            return null;
        }
        
        $start = $pos;
        
        // Read until whitespace, semicolon, brace, or quote
        while ($pos < $length && 
               !in_array($content[$pos], [' ', "\t", "\n", ';', '{', '}', '"', "'"])) {
            $pos++;
        }
        
        if ($pos === $start) {
            return null;
        }
        
        $token = substr($content, $start, $pos - $start);
        $this->debugLog("parseToken: found '$token'");
        return $token;
    }
    
    /**
     * Parse a value (which might be quoted)
     */
    private function parseValue($content, &$pos, $length) {
        $this->skipWhitespace($content, $pos, $length);
        
        if ($pos >= $length) {
            return null;
        }
        
        // Check if it's quoted
        if ($content[$pos] === '"' || $content[$pos] === "'") {
            $this->debugLog("parseValue: parsing quoted string");
            return $this->parseQuotedString($content, $pos, $length);
        }
        
        // Parse unquoted value
        $this->debugLog("parseValue: parsing unquoted token");
        return $this->parseToken($content, $pos, $length);
    }
    
    /**
     * Parse quoted string
     */
    private function parseQuotedString($content, &$pos, $length) {
        $quote = $content[$pos];
        $pos++; // Skip opening quote
        $result = '';
        
        while ($pos < $length) {
            $char = $content[$pos];
            
            if ($char === "\\") {
                if ($pos + 1 < $length && in_array($content[$pos + 1], ["\\", '"', "'"])) {
                    // Escaped character
                    $result .= $content[$pos + 1];
                    $pos += 2;
                    continue;
                } else {
                    $result .= $char;
                    $pos++;
                    continue;
                }
            } else if ($char === "\n") {
                $this->addError("Unterminated quote at line {$this->lineNumber}");
                return null;
            } else if ($char === $quote) {
                // Found closing quote
                $pos++; // Skip closing quote
                $this->debugLog("parseQuotedString: found '$result'");
                return $result;
            } else {
                $result .= $char;
                $pos++;
                if ($char === "\n") {
                    $this->lineNumber++;
                }
            }
        }
        
        $this->addError("Unterminated quote at line {$this->lineNumber}");
        return null;
    }
    
    /**
     * Skip whitespace
     */
    private function skipWhitespace($content, &$pos, $length) {
        $start = $pos;
        while ($pos < $length && in_array($content[$pos], [' ', "\t", "\r"])) {
            $pos++;
        }
        if ($pos > $start) {
            $this->debugLog("skipWhitespace: skipped " . ($pos - $start) . " characters");
        }
    }
    
    /**
     * Skip whitespace and comments
     */
    private function skipWhitespaceAndComments($content, &$pos, $length) {
        $iterations = 0;
        while ($pos < $length) {
            $iterations++;
            if ($iterations > 10000) {
                $this->addError("Infinite loop detected in skipWhitespaceAndComments");
                break;
            }
            
            $oldPos = $pos;
            
            // Skip whitespace
            if (in_array($content[$pos], [' ', "\t", "\r"])) {
                $pos++;
                continue;
            }
            
            // Handle newlines (increment line counter)
            if ($content[$pos] === "\n") {
                $this->lineNumber++;
                $pos++;
                continue;
            }
            
            // Handle # comments
            if ($content[$pos] === '#') {
                $this->debugLog("skipWhitespaceAndComments: found # comment");
                $this->skipToEndOfLine($content, $pos, $length);
                continue;
            }
            
            // Handle // comments
            if ($pos + 1 < $length && $content[$pos] === '/' && $content[$pos + 1] === '/') {
                $this->debugLog("skipWhitespaceAndComments: found // comment");
                $this->skipToEndOfLine($content, $pos, $length);
                continue;
            }
            
            // Handle /* */ comments
            if ($pos + 1 < $length && $content[$pos] === '/' && $content[$pos + 1] === '*') {
                $this->debugLog("skipWhitespaceAndComments: found /* comment");
                $this->skipBlockComment($content, $pos, $length);
                continue;
            }
            
            // If position didn't change, we're done
            if ($pos === $oldPos) {
                break;
            }
        }
    }
    
    /**
     * Skip to end of line
     */
    private function skipToEndOfLine($content, &$pos, $length) {
        while ($pos < $length && $content[$pos] !== "\n") {
            $pos++;
        }
    }
    
    /**
     * Skip block comment
     */
    private function skipBlockComment($content, &$pos, $length) {
        $commentStart = $this->lineNumber;
        $pos += 2; // Skip /*
        
        while ($pos + 1 < $length) {
            if ($content[$pos] === "\n") {
                $this->lineNumber++;
            } else if ($content[$pos] === '*' && $content[$pos + 1] === '/') {
                $pos += 2; // Skip */
                $this->debugLog("skipBlockComment: completed");
                return;
            }
            $pos++;
        }
        
        $this->addError("Comment started at line $commentStart does not end");
    }
    
    /**
     * Add error message
     */
    private function addError($message) {
        $this->errors[] = "{$this->filename}:{$this->lineNumber}: $message";
        if ($this->debug) {
            echo "[ERROR] $message\n";
        }
    }
    
    /**
     * Add warning message  
     */
    private function addWarning($message) {
        $this->warnings[] = "{$this->filename}:{$this->lineNumber}: $message";
        if ($this->debug) {
            echo "[WARNING] $message\n";
        }
    }
    
    /**
     * Get all errors
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Get all warnings
     */
    public function getWarnings() {
        return $this->warnings;
    }
}

// Rest of the classes remain the same...
class ConfigEntry {
    public $name;
    public $value;
    public $items = [];
    public $lineNumber;
    public $sectionLineNumber;
    public $filePositionStart;
    public $filePositionEnd;
    public $escaped = false;
    
    public function findEntry($name) {
        foreach ($this->items as $item) {
            if ($item->name === $name) {
                return $item;
            }
        }
        return null;
    }
    
    public function findAllEntries($name) {
        $result = [];
        foreach ($this->items as $item) {
            if ($item->name === $name) {
                $result[] = $item;
            }
        }
        return $result;
    }
    
    public function toArray() {
        $result = [
            'name' => $this->name,
            'value' => $this->value,
            'line' => $this->lineNumber
        ];
        
        if (!empty($this->items)) {
            $result['items'] = [];
            foreach ($this->items as $item) {
                $result['items'][] = $item->toArray();
            }
        }
        
        return $result;
    }
}

class UnrealConfigHelper {
    public static function extractBlocks($entries, $blockName) {
        $result = [];
        foreach ($entries as $entry) {
            if ($entry->name === $blockName) {
                $result[] = $entry;
            }
        }
        return $result;
    }
    
    public static function getListenBlocks($entries) {
        return self::extractBlocks($entries, 'listen');
    }
    
    public static function getOperBlocks($entries) {
        return self::extractBlocks($entries, 'oper');
    }
    
    public static function getMeBlock($entries) {
        $blocks = self::extractBlocks($entries, 'me');
        return !empty($blocks) ? $blocks[0] : null;
    }
    
    public static function getSetBlock($entries) {
        $blocks = self::extractBlocks($entries, 'set');
        return !empty($blocks) ? $blocks[0] : null;
    }
}

// Test with debug enabled:
echo "Starting UnrealIRCd config parser with debug logging...\n";

$parser = new UnrealConfigParser(true); // Enable debug logging
$result = $parser->parseFile('unrealircd.conf');

echo "\nParsing completed!\n";
echo "Found " . count($result['entries']) . " top-level entries\n";

if (!empty($result['errors'])) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $error) {
        echo "  $error\n";
    }
}

// Get specific blocks with null checking
$meBlock = UnrealConfigHelper::getMeBlock($result['entries']);
$listenBlocks = UnrealConfigHelper::getListenBlocks($result['entries']);
$operBlocks = UnrealConfigHelper::getOperBlocks($result['entries']);

// Safe access to block contents
if ($meBlock) {
    $nameEntry = $meBlock->findEntry('name');
    $infoEntry = $meBlock->findEntry('info');
    $serverName = $nameEntry ? $nameEntry->value : null;
    $serverInfo = $infoEntry ? $infoEntry->value : null;
    
    echo "\nServer info:\n";
    echo "  Name: " . ($serverName ?: "Not found") . "\n";
    echo "  Info: " . ($serverInfo ?: "Not found") . "\n";
} else {
    echo "\nNo 'me' block found\n";
}

echo "Listen blocks: " . count($listenBlocks) . "\n";
echo "Oper blocks: " . count($operBlocks) . "\n";
