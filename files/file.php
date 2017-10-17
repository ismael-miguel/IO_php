<?php
    namespace IO;

    final class InvalidFilename extends \Exception {
        function __construct($filename){
            $this->message = 'Invalid filename: "' . $filename . '"';
        }
    }
    final class InvalidMode extends \Exception {
        function __construct($mode){
            $this->message = 'Invalid mode: "' . $mode . '"';
        }
    }
    final class WriteError extends \Exception {
        function __construct($filename){
            $this->message = 'Could not write into "' . $filename . '"';
        }
    }
    final class ReadError extends \Exception {
        function __construct($filename){
            $this->message = 'Could not read from "' . $filename . '"';
        }
    }
    final class EOF extends \Exception {
        function __construct($filename){
            $this->message = 'End Of File "' . $filename . '"';
        }
    }

    final class File {

        const MODE_READ = 1;
        const MODE_WRITE = 2;
        const MODE_BINARY = 4;
        const MODE_ALL = 7;
		
        const MEMORY = "\1";
        const VOID = "\0";

        const EOL = PHP_EOL;
        const EOL_WIN = "\r\n";
        const EOL_LINUX = "\n";
        const EOL_OLD_MAC = "\r";

        private static $default_info = array(
            'content' => '',
            'path' => '',
            'name' => '',
            'length' => 0,
            'p' => 0,
            'mode' => self::MODE_READ
        );

        private $info = null;
		
		private static function is_real_file($filename) {
			return $filename != self::MEMORY && $filename != self::VOID;
		}

        public function __construct($filename, $mode = 1) {
			
			$this->info = self::$default_info;

            $this->info['mode'] = $mode & self::MODE_ALL;
            if(!$this->info['mode'])
            {
                throw new InvalidMode($mode);
            }
            
			if(!self::is_real_file($filename))
            {
                $this->info['path'] = $this->info['name'] = $filename;
				return;
            }

            $this->info['path'] = dirname($filename);
			if(!$this->info['path'])
			{
				$this->info['path'] = getcwd();
			}

			$this->info['name'] = basename($filename);
			if(!$this->info['path'])
			{
				throw new InvalidFilename($filename);
			}

			if($mode & self::READ)
			{
				$this->info['content'] = @file_get_contents($filename);

				if($this->info['content'] === false)
				{
					throw new ReadError($filename);
				}

				if(!($mode & self::MODE_BINARY))
				{
					$this->fix_eol();
				}

				$this->info['length'] = strlen($this->info['content']);
			}
        }

        function fix_eol() {
			
			// it's faster to store in a local variable for multiple accesses
            $content = str_replace(
				array(self::EOL_WIN, self::EOL_LINUX, self::EOL_OLD_MAC),
				self::EOL,
				$this->info['content']
			);
			
			// reset info to fix out-of-bounds bugs
			$this->info['p'] = 0;
			$this->info['length'] = strlen($content);
			$this->info['content'] = $content;
        }

        function eof() {
			
            switch($this->info['name'])
            {
                case self::VOID: // void doesn't have an end
                    return false;
                default:
                    return $this->info['p'] >= $this->info['length'];
            }
        }

        function read($bytes = -1) {
			
            if(
				$this->info['name'] == self::VOID
				|| !$bytes
			)
            {
				// void is always empty
                return '';
            }

            if($this->info['name'] != self::MEMORY && !($this->info['mode'] & self::MODE_READ))
            {
                throw new ReadError($this->info['name']);
            }

            if($this->eof())
            {
                throw new EOF($this->info['name']);
            }

            if($bytes < 0 || $bytes > ($this->info['length'] - $this->info['p']))
            {
                $bytes = $this->info['length'] - $this->info['p'];
            }

            $p = $this->info['p'];
			
            $this->info['p'] += $bytes;

            return substr($this->info['content'], $p, $bytes);
        }
		
        function readln($trim = true) {
			
            if($this->info['name'] == self::VOID)
            {
				// void is always empty
                return '';
            }

            if($this->info['name'] != self::MEMORY && !($this->info['mode'] & self::MODE_READ))
            {
                throw new ReadError( $this->info['name'] );
            }

            if($this->eof())
            {
                throw new EOF($this->info['name']);
            }
			
			if(
				!$this->info['content']
				|| !preg_match('@(?P<substr>[^\r\n]*)(?P<nl>\r\n?|\n)?@', $this->info['content'], $matches, 0, $this->info['p'])
			)
			{
				// if there's no content
				// or a freak accident happened with preg_match
				return '';
			}

            $this->info['p'] += strlen($matches[0]);

            return $trim ? $matches['substr'] : $matches[0];
        }
		
        function read_bytes($bytes) {
			
            $data = $this->read($bytes);
            $return = array();

            for($i = 0, $l = strlen($data); $i < $l; $i++)
            {
                $return[] = ord($data[$i]);
            }

            return $return;
        }
		
		function read_all() {
			return $this->info['content'];
		}
		
        function write($data) {
			
            if(
				$this->info['name'] == self::VOID
				|| !$data
			)
            {
				return $this;
            }
			
			if($this->info['name'] != self::MEMORY && !($this->info['mode'] & self::WRITE))
			{
				throw new WriteError($this->info['name']);
			}

			if(!($this->info['mode'] & self::MODE_BINARY))
			{
				$data = str_replace(
					array(self::EOL_WIN, self::EOL_LINUX, self::EOL_OLD_MAC),
					self::EOL,
					$data
				);
			}

			list($begin, $end) = array(
				substr($this->info['content'], 0, $this->info['p']),
				substr($this->info['content'], $this->info['p'])
			);

			$this->info['p'] = strlen($begin . $data);

			$this->info['content'] = $begin . $data . $end;

			$this->info['length'] = strlen($this->info['content']);

            return $this;
        }
		
        function writeln($data) {
            return $this->write($data . self::EOL);
        }
		
        function write_bytes() {
			
            $data = '';
			
            foreach(func_get_args() as $byte)
            {
                $data .= chr($byte);
            }
			
            return $this->write($data);
        }

        function seek($byte) {
			
            if($this->info['name'] === self::VOID || !is_numeric($byte))
            {
				return $this;
			}
			
			if($byte >= $this->info['length'])
			{
				$this->info['p'] = $this->info['length'];
			}
			else if($byte >= 0)
			{
				$this->info['p'] = (int)$byte;
			}
			else
			{
				$this->info['p'] = $this->info['length'] - 1;
			}

            return $this;
        }
        function start() {
            return $this->seek( 0 );
        }
        function end() {
            return $this->seek( -1 );
        }

        function pos() {
            return $this->info['p'];
        }

        function size() {
            return $this->info['length'];
        }

        function save() {
		
            if(
				$this->info['name'] === self::VOID
				|| $this->info['name'] === self::MEMORY
			)
            {
                return $this->info['name'] === self::MEMORY;
            }
			
			$result = @file_put_contents(
				$this->info['path'] . DIRECTORY_SEPARATOR . $this->info['name'],
				$this->info['content']
			);
			
			if($result === false)
			{
				throw new WriteError($this->info['name']);
			}
			
			return true;
        }

        function destroy() {
            $this->info = self::$default_info;
        }
    }
