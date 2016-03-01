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

/**
 * Podcast Preparation Tool.
 * 
 * *PLEASE NOTE* This tool relies on the following external tools:
 * 
 * * eyeD3 0.6.18 from http://eyed3.nicfit.net/
 *   (Right now it must be exactly that version. 0.7 and up don't work)
 *
 * * mp3splt from http://mp3splt.sourceforge.net/
 *   
 * * an external editor to do last minute tracklisting tidy-up. 
 *   Defaults to vim
 *  
 */

$config = array(
    'image_dir' => '/Users/ben/Pictures',
    'shows' => array(
        'bassdrive' => array(
            'image' => 'xposure-show.jpg',
            'album' => 'http://www.bassdrive.com/',
            'genre' => 'Drum & Bass',
            'tags' => array('drum & bass', 'dnb', 'neurofunk', 'liquid', 'jump up'),
            'description' => "This show aired on :date: mixed by :artist:\n\nBen XO presents the XPOSURE Show on http://www.bassdrive.com every Tuesday, 9-11pm GMT since 2001.",
            'date_offset' => 0,
            'mixcloud' => true
        ),
        'di.fm' => array(
            'image' => 'xpression-session-600.jpg',
            'album' => 'http://di.fm/electro',
            'genre' => 'Electro House',
            'tags' => array('electro house', 'tech house', 'uk funky', 'electro', 'progressive house'),
            'description' => "This show aired on :date: mixed by :artist:\n\nBen XO presents the XPRESSION Session on http://di.fm/electro on the 1st Tuesday of the month, 5-7pm GMT since 2010.",
            'date_offset' => 1,
            'mixcloud' => true
        ),
    ),
);

/*** Interfaces **/

interface Editable
{
    public function asText();
    public function fromText($text);
}

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

class PPError
{
    const MISSING_ARG_NO_SHOW    = -100;
    const MISSING_ARG_NO_MP3     = -101;
    const NO_SHOWS_CONFIGURED    = -102;
    const SHOW_NOT_CONFIGURED    = -103;
    const NO_IMAGE_CONFIGURED    = -104;
    const IMAGE_NOT_FOUND        = -105;
    const IMAGE_UNREADABLE       = -106;
    const NO_IMAGE_DIR_CONFIG    = -107;
    const NO_ALBUM_CONFIGURED    = -108;
    const NO_GENRE_CONFIGURED    = -109;
    const NO_SERATO_DIR_DETECT   = -110;
    const SERATO_DIR_NOT_FOUND   = -111;
    const SERATO_DIR_UNREADABLE  = -112;
    const RECENT_FILE_NOT_FOUND  = -113;
    const MP3TRIM_FAILED         = -114;
    const TRIM_RENAME_FAILED     = -115;
    const TRIM_DELETE_FAILED     = -116;
    const TRACKLIST_FORMAT       = -117;
    const TRACK_STARTIME_FORMAT  = -118;
    const SERATO_CSV_OPEN_FAILED = -119;
    const SERATO_CSV_MISSING_COL = -120;
    const GUESS_DATE_FAILED      = -121;
    const SET_ARTIST_TITLE_FAIL  = -122;
    const EYED3_TAG_FAILED       = -123;
    const EYED3_IMAGE_FAILED     = -124;
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

        try {

            if(empty($argv[1]))
                throw new InvalidArgumentException('No show specified.', PPError::MISSING_ARG_NO_SHOW);

            if(empty($argv[2]))
                throw new InvalidArgumentException('No MP3 specified.', PPError::MISSING_ARG_NO_MP3);

            $show_setting_name  = $argv[1];
            $mp3_file_name      = $argv[2];

            $config = $this->getShowConfig($show_setting_name);

            $image_file_name = $this->getImageFileName($config);
            $mp3file = $this->tagMP3($mp3_file_name, $image_file_name, $config);

            // we need the date for the tracklist search too
            $date_offset = isset($config['date_offset']) ? $config['date_offset'] : 0;
            $date = $mp3_file->guessDateFromFilename($date_offset);
            $mp3_file->setYear($date->format('Y'));

            $tracklist = $this->getTracklistFromSeratoCSV($date, $config);

            // allow last minute tidying of tracklist with vim
            $tracklist->editWithEditor();

            $mp3_file->setTracklist($tracklist);

            $this->out("Type Ctrl-C now to break...\n");
            sleep(2);

            $this->out("Trimming silence...\n");
            $mp3_file->trimSilence();

            $this->out("Writing ID3 info to file...\n");
            $mp3_file->applyID3();

            if($config[$show]['mixcloud'])
            {
                $mixcloud = new MixcloudClient($config, $image_file_name);
                $mixcloud->setMP3File($mp3_file);
                $mixcloud->upload();
            }

        } catch(InvalidArgumentException $e) {
            $this->out( $e->getMessage() . "\n" );
            $this->usage(basename($argv[0]));
        } catch(Exception $e) {
            $this->out( $e->getMessage() . "\n" );
        }

        if(isset($e)) exit($e->getCode());

    }

    protected function getShowConfig($show_name)
    {
        if(empty($this->config['shows']))
            throw new RuntimeException('No shows configured.', PPError::NO_SHOWS_CONFIGURED);

        if(empty($this->config['shows'][$show_name]))
        {
            throw new InvalidArgumentException(
                sprintf('Unknown show \'%s\'. Only know about %s', $show_name, implode(', ', array_keys($config['shows']))), 
                PPError::SHOW_NOT_CONFIGURED
            );
        }

        return $this->config['shows'][$show_name];
    }

    protected function tagMP3($mp3_file_name, $image_file_name, $config)
    {
        if(empty($config['album']))
        {
            throw new RuntimeException(
                "No 'album' configured for podcast. (Suggest a Podcast name or URL.)",
                PPError::NO_ALBUM_CONFIGURED
            );
        }

        if(empty($config['genre']))
        {
            throw new RuntimeException(
                "No 'genre' configured for podcast.",
                PPError::NO_GENRE_CONFIGURED
            );
        }

        $mp3_file = new MP3File($mp3_file_name);
        $mp3_file->setArtistAndTitleFromFilename();
        $mp3_file->setAlbum($config['album']);
        $mp3_file->setGenre($config['genre']);
        $mp3_file->setImage($image_file_name);

        return $mp3_file;
    }

    protected function getImageFileName($config)
    {
        if(!isset($config['image']))
            throw new RuntimeException('Image not configured. Must be a filename or false.', PPError::NO_IMAGE_CONFIGURED);

        // config says: I don't want an image.
        if($config['image'] === false)
            return false;

        if(!isset($config['image_dir']))
            throw new RuntimeException('Image directory not configured.', PPError::NO_IMAGE_DIR_CONFIG);

        $image_file_name = $config['image_dir'] . DIRECTORY_SEPARATOR . $config[$show]['image'];

        if(!is_file($image_file_name))
            throw new RuntimeException(sprintf("No such file '%s'", $image_file_name), PPError::IMAGE_NOT_FOUND);

        if(!is_readable($image_file_name))
        {
            throw new RuntimeException(
                sprintf("Can't read image file '%s' (permissions?)", $image_file_name),
                PPError::IMAGE_UNREADABLE
            );
        }

        return $image_file_name;
    }

    protected function getTracklistFromSeratoCSV(DateTime $date, array $config)
    {
        if(isset($config['serato_history_dir']))
        {
            $serato_history_dir = $config['serato_history_dir'];

            if(!is_dir($serato_history_dir))
            {
                throw new RuntimeException(
                    sprintf("No such serato history export dir '%s'", $serato_history_dir), 
                    PPError::SERATO_DIR_NOT_FOUND
                );
            }

            if(!is_readable($serato_history_dir))
            {
                throw new RuntimeException(
                    sprintf("Can't read from Serato history export dir '%s' (permissions?)", $serato_history_dir), 
                    PPError::SERATO_DIR_UNREADABLE
                );
            }
        }
        else
        {
            $serato_history_dir = $this->getDefaultSeratoHistoryDir();
        }

        $serato_history_file_name = $serato_history_dir . DIRECTORY_SEPARATOR . $date->format('d-m-Y') . '.csv';
        if(!file_exists($serato_history_file_name)) 
        {
            $this->out("Didn't find history file for date {$date->format('Y-m-d')}, looking for newest history file...\n");
            $serato_history_file_name = $this->getMostRecentFile($serato_history_dir, 'csv');
        }

        $tracklist = new Tracklist();
        $tracklist->fromIterator(new SeratoCSVIterator($serato_history_file_name));

        return $tracklist;
    }

    protected function getDefaultSeratoHistoryDir()
    {
        // OSX
        $dir = getenv('HOME') . '/Music/ScratchLIVE/History Export';
        if(is_dir($dir) && is_readable($dir)) return $dir;

        $dir = getenv('HOME') . '/Music/_Serato_/History Export';
        if(is_dir($dir) && is_readable($dir)) return $dir;
        
        // Windows Vista / Windows 7 ?
        $dir = getenv('USERPROFILE') . '\Music\ScratchLIVE\History Export';
        if(is_dir($dir) && is_readable($dir)) return $dir;

        $dir = getenv('USERPROFILE') . '\Music\_Serato_\History Export';
        if(is_dir($dir) && is_readable($dir)) return $dir;
        
        // Windows XP
        $dir = getenv('USERPROFILE') . '\My Documents\My Music\ScratchLIVE\History Export';
        if(is_dir($dir) && is_readable($dir)) return $dir;

        $dir = getenv('USERPROFILE') . '\My Documents\My Music\_Serato_\History Export';
        if(is_dir($dir) && is_readable($dir)) return $dir;
        
        throw new RuntimeException(
            "Could not find your Serato 'History Export' folder. Please configure it manually.", 
            PPError::NO_SERATO_DIR_DETECT
        );
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

        throw new RuntimeException(
            sprintf("No %s file found in %s", $type, $from_dir), 
            PPError::RECENT_FILE_NOT_FOUND
        );
    }
}

class Track implements Editable
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

    public function getStartTimeInSeconds()
    {
        $s = explode(':', $this->start_time);
        return 3600 * $s[0] + 60 * $s[1] + $s[2];
    }
    
    public function asText()
    {
        return sprintf("%s - %s - %s",
            $this->getStartTime(),
            $this->getArtist(),
            $this->getTitle()
        );
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
            throw new InvalidArgumentException("Didn't understand the start-time '$st'", PPError::TRACK_STARTIME_FORMAT);
        
        $this->start_time = $st;
    }

    public function adjustTimeTo($base_time)
    {
        $adj_time = new PodcastDateTime($base_time);
        $old_time = new PodcastDateTime($this->start_time);
        $new_time = new PodcastDateTime('@' . ($old_time->getTimestamp() - $adj_time->getTimestamp()) );
        $this->start_time = $new_time->format('H:i:s');
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
            throw new InvalidArgumentException("Could not parse track '$text'", PPError::TRACKLIST_FORMAT);
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
class Tracklist implements Editable
{
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
    
    /**
     * @return array of Track
     */
    public function getTracks()
    {
        return $this->tracks;
    }
    
    public function asCue($filename)
    {
        
    }
    
    public function asText()
    {
        $string = '';
        $row = 1;
        foreach($this->tracks as $track)
        {
            $line = '';
            $line .= sprintf("%02d - ", $row);
            $line .= $track->asText();
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

        if(!empty($tracks))
        {
            // First time might not start at 0; adjust all times as if it does
            $base_time = $tracks[0]->getStartTime();
            foreach($tracks as $track)
            {
                $track->adjustTimeTo($base_time);
            }
        }

        $this->tracks = $tracks;
    }
    
    public function fromIterator(Iterator $iterator)
    {
        $tracks = array();
        foreach($iterator as $track)
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
        file_put_contents($tempfile, $this->asText());
        passthru(escapeshellarg($editor_cmd) . ' 2>/dev/null ' . escapeshellarg($tempfile));
        $this->fromText( file_get_contents($tempfile) );
        unlink($tempfile);
    }
}

class SeratoCSVIterator implements Iterator
{
    // map of column field names to column numbers
    protected $column_field = array ();

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
        $t->setTitle($row[$this->column_field['name']]);
        $t->setArtist($row[$this->column_field['artist']]);
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
            throw new InvalidArgumentException("Could not open file '$filename'", PPError::SERATO_CSV_OPEN_FAILED);

        $this->configureFromHeader(fgetcsv($fh));

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
        $row = $this->rows[$this->ptr];
        $time = new PodcastDateTime($row[$this->column_field['start time']]);
        
        if($this->ptr == 0) 
        {
            $this->base_timestamp = $time->getTimestamp();
            return '00:00:00';   
        }
        
        $duration = new PodcastDateTime('@' . ($time->getTimestamp() - $this->base_timestamp) );
        return $duration->format('H:i:s');
    }

    protected function configureFromHeader($header_row)
    {
        foreach($header_row as $column => $header_field_name)
        {
            $this->column_field[$header_field_name] = $column;
        }

        foreach(array('name', 'artist', 'start time') as $column_name)
        {
            if(!isset($this->column_field[$column_name]))
            {
                throw new RuntimeException("No column '$column_name' found in header!", PPError::SERATO_CSV_MISSING_COL);
            }
        }
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
    public function guessDateFromFilename($date_offset=0)
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
                throw new RuntimeException("Could not parse date from '$filename'", PPError::GUESS_DATE_FAILED);
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

    public function getArtist() { return $this->artist; }
    public function getTitle() { return $this->title ; }
    public function getAlbum() { return $this->album ; }
    public function getGenre() { return $this->genre ; }
    public function getYear() { return $this->year ; }
    public function getImage() { return $this->image ; }
    
    public function setTracklist(Tracklist $t)
    {
        $this->tracklist = $t;
    }
    
    /**
     * @var Tracklist
     */
    public function getTracklist()
    {
        return $this->tracklist;
    }
        
    public function setArtistAndTitleFromFilename()
    {
        $filename = basename($this->getFilename());
        if(preg_match('/^([^-]+)\s+-\s+(.+\s\(.+?\))/', $filename, $matches))
        {
            $this->artist = $matches[1];
            $this->title = $matches[2];
        }
        else
        {
            throw new RuntimeException("Could not parse artist and title from '$filename'", PPError::SET_ARTIST_TITLE_FAIL);
        }
    }
    
    public function setYearFromFilename($date_offset=null)
    {
        $date = $this->guessDateFromFilename($date_offset);
        $this->setYear($date->format('Y'));
    }

    public function trimSilence($debug=false)
    {
        $args = array(
            'mp3splt',
            '-r',
            '-p',
            '-min=0.5',
            $this->getFilename()
        );

        if($debug)
        {
            print   (implode(' ', array_map('escapeshellarg', $args)));
        }
        else
        {
            passthru(implode(' ', array_map('escapeshellarg', $args)), $retval);
            if(0 !== $retval) throw new RuntimeException('Call to mp3splt to trim file failed', PPError::MP3TRIM_FAILED);

            $retval = unlink($this->getFilename());
            if(false === $retval) throw new RuntimeException('Delete untrimmed file failed', PPError::TRIM_DELETE_FAILED);

            $retval = rename($this->getBasename('.mp3') . '_trimmed.mp3', $this->getFilename());
            if(false === $retval) throw new RuntimeException('Renaming trimmed file failed', PPError::TRIM_RENAME_FAILED);
        }
    }
    
    public function applyID3($debug=false)
    {
        // NOTE: THIS ORDERING IS VERY SPECIFIC!
        $args = array (
            'eyed3_script',
            '--to-v2.3', // this keeps iTunes and id3v2 happy.
            '--set-encoding=utf16-LE',
            '--itunes', // eyeD3 0.6.18 only (0.7 and above don't work with this script yet)
            '--year=' . $this->year,
            '--comment=::' . $this->tracklist->asText(),
            '--title=' . $this->title,
            '--artist=' . $this->artist,
            '--album=' . $this->album,
            '--genre=' . $this->genre
        );
        
        //if($this->image) $args[] = '--add-image=' . $this->image . ':OTHER';
        
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
                throw new RuntimeException('Call to eyeD3 to apply the tags failed', PPError::EYED3_TAG_FAILED);
            }
        }
        
        if($this->image)
        {
            // Now do the image as a second step, otherwise it seems to screw up and come up blank in iTunes :/
            $args = array (
                'eyed3_script',
                '--add-image=' . $this->image . ':OTHER',
                $this->getFilename()
            );
            
            if($debug)
            {
                print   (implode(' ', array_map('escapeshellarg', $args)));
            }
            else
            {
                passthru(implode(' ', array_map('escapeshellarg', $args)), $retval);
                if(0 !== $retval)
                {
                    throw new RuntimeException('Call to eyeD3 to apply the image tag failed', PPError::EYED3_IMAGE_FAILED);
                }
            }
        }    
    }
}

class MixcloudClient
{
    /**
     * @var MP3File
     */
    protected $mp3_file;
    
    protected $access_token;
    protected $tags;
    protected $description;
    protected $picture;
    protected $date_offset;
    
    public function __construct(array $config, $picture)
    {
        $this->date_offset = $config['date_offset'];
        $this->access_token = $config['mixcloud']['access_token'];
        $this->tags = $config['tags'];
        $this->description = $config['description'];
        $this->picture = $picture;
    }
    
    public function setMP3File(MP3File $mp3_file)
    {
        $this->mp3_file = $mp3_file;
    }
    
    public function upload()
    {
        $fn = $this->mp3_file->getFilename();
        
        $url = 'https://api.mixcloud.com/upload/?access_token=' . $this->access_token;
        $command = sprintf(
            'curl -v -# -F mp3=@%s -F picture=@%s -F "name="%s %s %s -F "publish_date="%s -F "description="%s %s | tee',
            escapeshellarg($fn),
            escapeshellarg($this->picture),
            escapeshellarg($this->mp3_file->getArtist() . ' - ' . $this->mp3_file->getTitle()),
            $this->getTagsArgs(),
            $this->getTracklistArgs(),
            escapeshellarg($this->getPublishDate()),
            escapeshellarg($this->getDescription()),
            $url
        );
        
        echo "***\n";
        echo "upload with:\n\n". $command . "\n";
    }
    
    public function getTagsArgs()
    {
        $args = '';
        $i = 0;
        foreach($this->tags as $tag)
        {
            $args .= '-F tags-' . $i . '-tag=' . escapeshellarg($tag) . ' ';
            $i++; 
        }
        return $args;
    }
    
    public function getTracklistArgs()
    {
        $args = '';
        $tracks = $this->mp3_file->getTracklist()->getTracks();
        $i = 0;
        foreach($tracks as $track)
        {
            /* @var $track Track */
            $args .= '-F sections-' . $i . '-artist=' . escapeshellarg($track->getArtist()) . ' ' . 
                     '-F sections-' . $i . '-song=' . escapeshellarg($track->getTitle()) . ' ' .
                     '-F sections-' . $i . '-start_time=' . escapeshellarg($track->getStartTimeInSeconds()) . ' '
            ;
            $i++;
        }
        return $args;
    }
    
    public function getDescription()
    {
        $desc = $this->description;
        $desc = str_replace(
            array(':date:', ':artist:'), 
            array( $this->mp3_file->guessDateFromFilename($this->date_offset)->format('l jS F Y'), $this->mp3_file->getArtist() ), 
            $desc
        );
        return $desc;
    }

    public function getPublishDate()
    {
        // set to 9:00am the next morning
        $now = new DateTime();
        if($now->format('H') >= 9)
        {
            // assume we want to publish tomorrow
            $publish_date = $now->modify("+1 days");
        }
        else
        {
            // up late publishing podcasts after midnight, are we?
            $publish_date = $now;
        }

        return $publish_date->setTime(9, 0)->format('Y-m-d\TH:i:s\Z');
    }

}

/*** App ***/

$p = new PreparePodcast($config, $serato_history_dir, $image_dir);
$p->main($argc, $argv);
