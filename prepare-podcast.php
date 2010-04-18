#!/usr/bin/env php
<?php

/******************************************************************************
 * Copyright (c) 2010, Ben XO (me@ben-xo.com).
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 ******************************************************************************
 */


$serato_history_dir = '/Users/ben/Music/ScratchLive/History Export';
$image_dir = '/Users/ben/Pictures';

$config = array(
    'bassdrive' => array(
        'image' => 'xposure-show.jpg',
        'album' => 'http://www.bassdrive.com/',
        'genre' => 'Drum & Bass',
        'date_offset' => 0
    ),
    'di.fm' => array(
        'image' => false,
        'album' => 'http://di.fm/electro',
        'genre' => 'Electro House',
        'date_offset' => 1
    ),
);

/*** Classes ***/

if(version_compare(PHP_VERSION, '5.3.0') >= 0) 
{
    class PodcastDateTime extends DateTime { }
}
else
{
    class PodcastDateTime extends DateTime
    {
        public function getTimestamp()
        {
            return $this->format('U');
        }
    }
}

class PreparePodcast
{
    protected $config, $serato_history_dir, $image_dir;
    
    public function __construct($config, $serato_history_dir, $image_dir)
    {
        $this->config = $config;
        $this->serato_history_dir = $serato_history_dir;
        $this->image_dir = $image_dir;
    }
    
    public function out($t)
    {
        echo $t;
    }
    
    public function main($argc, array $argv)
    {
        date_default_timezone_set('UTC');
        
        $config = $this->config;
        $serato_history_dir = $this->serato_history_dir;
        $image_dir = $this->image_dir;
        
        try {
        
            if(!is_dir($serato_history_dir) || !is_readable($serato_history_dir))
                throw new RuntimeException('Serato History dir not set correctly in ' . $argv[0]);
            
            if(!is_dir($image_dir) || !is_readable($image_dir))
                throw new RuntimeException('Image dir not set correctly in ' . $argv[0]);
                        
            if(empty($argv[1])) 
                throw new InvalidArgumentException('No show specified.');
                
            $show = $argv[1];
            if(!isset($config[$show]))
                throw new InvalidArgumentException('Unknown show \'' . $show . '\'. Only know about ' . implode(', ', array_keys($config)));
                
            if(empty($argv[2])) 
                throw new InvalidArgumentException('No MP3 specified.');
                
            $mp3_file_name = $argv[2];
            
            if($config[$show]['image'] !== false)
            {
                $image_file = $image_dir . '/' . $config[$show]['image'];
        
                if(!is_file($image_file) || !is_readable($image_file))
                    throw new RuntimeException('Can\'t read image file ' . $image_file);
            }
            else
            {
                $image_file = false;
            }
            
            $mp3_file = new MP3File($mp3_file_name);
            $mp3_file->setArtistAndTitleFromFilename();
            $mp3_file->setAlbum($config[$show]['album']);
            $mp3_file->setGenre($config[$show]['genre']);
            $mp3_file->setImage($image_file);
            
            $date = $mp3_file->guessDateFromFilename($config[$show]['date_offset']);
            $mp3_file->setYear($date->format('Y'));
            
            $serato_history_file_name = $serato_history_dir . '/' . $date->format('d-m-Y') . '.csv';
            if(!file_exists($serato_history_file_name)) 
            {
                $this->out("Didn't find history file for date {$date->format('Y-m-d')}, looking for newest history file...\n");
                $serato_history_file_name = $this->getMostRecentFile($serato_history_dir, 'csv');
            }
            
            $tracklist = new Tracklist();
            $tracklist->fromSeratoCSV($serato_history_file_name);
            
            // allow last minute tidying of tracklist with vim
            $tracklist->editWithEditor();
            
            $mp3_file->setTracklist($tracklist);
            
            $this->out("Type Ctrl-C now to break...\n");
            sleep(2);
            
            $this->out("Writing info to file...\n");
            
            $mp3_file->applyID3();
        
        } catch(InvalidArgumentException $e) {
            $this->out( $e->getMessage() . "\n" );
            $this->usage(basename($argv[0]));
        } catch(Exception $e) {
            $this->out( $e->getMessage() . "\n" );
        }
        
        if(isset($e)) exit(-1);
    }
    
    protected function usage($cmd)
    {
        $this->out( "Usage: $cmd <show> <filename>\n" );
    }
    
    protected function getMostRecentFile($from_dir, $type)
    {
        $newest_mtime = 0;
        $fp = '';
        
        $di = new DirectoryIterator($from_dir);
        foreach($di as $f)
        {
            if(!$f->isFile() || !substr($f->getFilename(), -4) == '.' . $type)
                continue;
    
            $mtime = $f->getMTime();
            if($mtime > $newest_mtime)
            {
                $newest_mtime = $mtime;
                $fp = $f->getPathname();
            }
        }
        if($fp) return $fp;
        throw new RuntimeException("No $type file found in $from_dir");
    }
}

class Track
{
    protected $start_time, $artist, $title;
    
    // getters
    
    public function getArtist()
    {
        return $this->artist;
    }
    
    public function getTitle()
    {
        return $this->title;
    }
    
    public function getStartTime()
    {
        return $this->start_time;
    }

    public function asText($with_start_times = false)
    {
        if($with_start_times)
        {
            return sprintf("%s - %s - %s", 
                $this->getStartTime(), 
                $this->getArtist(), 
                $this->getTitle()
            );
        }
        else
        {
            return sprintf("%s - %s",
                $this->getArtist(), 
                $this->getTitle()
            );            
        }
    }
    
    public function __toString()
    {
        return $this->asText();
    }
    
    // setters
    
    public function setArtist($a)
    {
        $this->artist = trim($a);
    }
    
    public function setTitle($t)
    {
        $this->title = $this->normaliseTitle(trim($t));
    }
    
    public function setStartTime($st)
    {
        if(!preg_match('/^\d{2,}:[0-5]\d:[0-5]\d$/', $st))
            throw new InvalidArgumentException("Didn't understand the start-time '$st'");
        
        $this->start_time = $st;
    }
    
    public function fromText($text)
    {
        if(preg_match('/^(?:\d\d - )?(?:(\d\d:\d\d:\d\d) - )?(.+?) - (.*)/', $text, $matches))
        {
            if($matches[1]) $this->setStartTime($matches[1]);
            $this->setArtist($matches[2]);
            $this->setTitle($matches[3]);
        }
        else
        {
            throw new InvalidArgumentException("Could not parse track '$text'");
        }
    }
        
    protected function normaliseTitle($t) {
    	return preg_replace(
    		array( '/ - (.+)$/', '/ \(Original Mix\)/i' ), 
    		array( ' ($1)',      '' ), 
    		$t
    	);
    }
}

/**
 * Represents the entire mix tracklist. You can use "fromText()" to populate this
 * from a freeform textual tracklist, or addTrack() to add tracks one by one.
 * You only need to use setArtist() and setTitle() if you want to convert the Tracklist
 * to a cue sheet with asCue().
 */
class Tracklist
{
    const POSITION = 1;
    const START_TIME = 2;
    const ALL = 3;
    
    /** @var array of Track */
    protected $tracks = array();
    
    protected $artist, $title; // for cue sheet
    
    /**
     * Set the artist of the whole mix (usually the DJ)
     */
    public function setArtist($artist)
    {
        $this->artist = $artist;
    }
    
    /**
     * Set the title for the whole mix
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }
    
    public function addTrack(Track $track)
    {
        $this->tracks[] = $track;
    }

    public function clear()
    {
        $this->tracks = array();
    }
    
    public function asCue($filename)
    {
        
    }
    
    public function asText($flags = self::POSITION)
    {
        $string = '';
        $row = 1;
        foreach($this->tracks as $track)
        {
            $line = '';
            if($flags & self::POSITION) $line .= sprintf("%02d - ", $row);   
            $line .= $track->asText($flags & self::START_TIME);
            $string .= $line . "\n";
            $row++;
        }
        return $string;
    }
    
    public function fromText($text)
    {
        $rows = explode("\n", $text);
        $tracks = array();
        foreach ($rows as $row)
        {
            if (empty($row)) continue;
            $track = new Track();
            $track->fromText($row);
            $tracks[] = $track;
        }
        $this->tracks = $tracks;
    }
    
    public function fromSeratoCSV($filename)
    {
        $tracks = array();
        foreach(new SeratoCSVIterator($filename) as $track)
        {
            $tracks[] = $track;
        }
        $this->tracks = $tracks;
    }
    
    public function __toString()
    {
        return $this->asText();
    }

    function editWithEditor($editor_cmd='vim') 
    {
        $tempfile = tempnam('', 'podcast');
        file_put_contents($tempfile, $this->asText( Tracklist::ALL ));
        passthru(escapeshellarg($editor_cmd) . ' 2>/dev/null ' . escapeshellarg($tempfile));
        $this->fromText( file_get_contents($tempfile) );
        unlink($tempfile);
    }
}

class SeratoCSVIterator implements Iterator
{
    protected $rows = array();
    protected $base_timestamp;
    protected $ptr = 0;
    
    /**
	 * @return Track
     */
    public function current()
    {
        $row = $this->rows[$this->ptr];
        $t = new Track();
        $t->setTitle($row[0]);
        $t->setArtist($row[1]);
        $t->setStartTime( $this->getRelativeStartTime() );
        return $t;
    }
    
    public function key()
    {
        return $this->ptr + 1; // tracklists are 1-based
    }
    
    public function next()
    {
        $this->ptr++;       
    }
    
    public function rewind()
    {
        $this->ptr = 0;
    }
    
    public function valid()
    { 
        return isset($this->rows[$this->ptr]);
    }
    
    public function __construct($filename=null)
    {
        if(isset($filename))
        {
            $this->parseFromFile($filename);
        }
    }
    
    public function parseFromFile($filename)
    {
        $fh = fopen($filename, 'r');
        if(!$fh) 
            throw new InvalidArgumentException("Could not open file '$filename'");
        
        fgetcsv($fh); // throw away header lines
        fgetcsv($fh);
        
        while(!feof($fh))
        {
            $row = fgetcsv($fh);
            if(!empty($row)) $this->rows[] = $row;
        }

        fclose($fh);
    }
    
    protected function getRelativeStartTime()
    {
        $time = new PodcastDateTime($this->rows[$this->ptr][2]);
        
        if($this->ptr == 0) 
        {
            $this->base_timestamp = $time->getTimestamp();
            return '00:00:00';   
        }
        
        $duration = new PodcastDateTime('@' . ($time->getTimestamp() - $this->base_timestamp) );
        return $duration->format('H:i:s');
    }
}

class AFile extends SplFileInfo
{
    /** @var DateTime */
    protected $date;
    
    /**
     * Returns date in dd-mm-yyyy format
     * @param $date_offset: number of days show was recorded prior to the date on the filename
     * @return DateTime
     */
    public function guessDateFromFilename($date_offset)
    {
        if(!isset($this->date))
        {
            $filename = $this->getFilename();
            $date = '';
            
            // files with yyyy-mm-dd in
            if(preg_match('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $filename, $matches))
            {
                $date = new DateTime($matches[0]);
            }
            
            // files with yyyymmdd in
            elseif(preg_match('/(?<!\d)20\d{6}/', $filename, $matches))
            {
                $date = new DateTime(substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 4));
            }
            
            // files with dd month yyyy in
            elseif(preg_match('/\d\d\s[a-zA-Z]+\s\d{4}/', $filename, $matches))
            {
                $date = new DateTime($matches[0]);
            }
            
            else
            {
                throw new RuntimeException("Could not parse date from '$filename'");
            }
            
            if($date_offset)
            {
                $date->modify("-$date_offset days");
            }
            $this->date = $date;
        }
        
        return $this->date;
    }
}

class MP3File extends AFile
{
    
    /** @var Tracklist **/
    protected $tracklist;
    protected $image = false;
    protected $artist, $title, $album, $genre, $year;
    
    public function setArtist($s) { $this->artist = $s; }
    public function setTitle($s) { $this->title = $s; }
    public function setAlbum($s) { $this->album = $s; }
    public function setGenre($s) { $this->genre = $s; }
    public function setYear($s) { $this->year = $s; }
    public function setImage($s) { $this->image = $s; }
    
    public function setTracklist(Tracklist $t)
    {
        $this->tracklist = $t;
    }
        
    public function setArtistAndTitleFromFilename()
    {
        $filename = basename($this->getFilename());
        if(preg_match('/^([^-]+)\s+-\s+([^-]+\s\(.+\))/', $filename, $matches))
        {
            $this->artist = $matches[1];
            $this->title = $matches[2];
        }
        else
        {
            throw new RuntimeException("Could not parse artist and title from '$filename'");
        }
    }
    
    public function setYearFromFilename($date_offset=null)
    {
        $date = $this->guessDateFromFilename($date_offset);
        $this->setYear($date->format('Y'));
    }
    
    public function applyID3($debug=false)
    {
        // NOTE: THIS ORDERING IS VERY SPECIFIC!
        $args = array (
        	'eyed3',
            '--to-v2.3', // this keeps iTunes and id3v2 happy.
            '--set-encoding=utf16-LE',
            '--itunes', // eyeD3 0.6.17 (latest at time of writing) needs the patch from http://www.ben-xo.com/eyeD3 for this
            '--year=' . $this->year,
            '--comment=::' . $this->tracklist->asText(),
            '--title=' . $this->title,
        	'--artist=' . $this->artist,
            '--album=' . $this->album,
            '--genre=' . $this->genre
        );
        
        if($this->image) $args[] = '--add-image=' . $this->image . ':OTHER';
        
        $args[] = $this->getFilename();
        
        if($debug)
        {
            print   (implode(' ', array_map('escapeshellarg', $args)));
        }
        else
        {
            passthru(implode(' ', array_map('escapeshellarg', $args)), $retval);
            if(0 !== $retval)
            {
                throw new RuntimeException('Call to eyeD3 to apply the tags failed');
            }
        }
    }
}

/*** App ***/

$p = new PreparePodcast($config, $serato_history_dir, $image_dir);
$p->main($argc, $argv);
