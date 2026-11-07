<?php

/**
 * Files uploaded through bigz.roundsphere.com, kept in uploads_dir (outside the web root)
 */
class uploads
{
    /**
     * Saves one uploaded file ($_FILES entry) under a safe, unused name. Returns the name it was saved as.
     */
    public static function save($tmpPath, $originalName)
    {
        $name = self::safeName($originalName);
        $dot  = strrpos($name, '.');
        $base = $dot ? substr($name, 0, $dot) : $name;
        $ext  = $dot ? substr($name, $dot) : '';
        // Never overwrite: "lease.pdf" becomes "lease (2).pdf", "lease (3).pdf", ...
        for ($copy = 2; file_exists(self::dir().'/'.$name); $copy++) {
            $name = "{$base} ({$copy}){$ext}";
        }
        if (!move_uploaded_file($tmpPath, self::dir().'/'.$name)) {
            throw new \RuntimeException("Could not save {$name}");
        }
        // Readable by ubuntu (owner of uploads_dir), whose scripts work with the files
        chmod(self::dir().'/'.$name, 0644);
        return $name;
    }

    /**
     * The most recent uploads, newest first: [['name' => ..., 'size' => bytes, 'time' => unix time], ...]
     */
    public static function recent($limit = 25)
    {
        $files = [];
        foreach (new \DirectoryIterator(self::dir()) as $file) {
            if ($file->isFile()) {
                $files[] = ['name' => $file->getFilename(), 'size' => $file->getSize(), 'time' => $file->getMTime()];
            }
        }
        usort($files, fn ($first, $second) => $second['time'] <=> $first['time']);
        return array_slice($files, 0, $limit);
    }

    /**
     * A file name that is safe on disk: no path, no leading dot, only plain characters, at most 150 characters
     */
    public static function safeName($originalName)
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[^A-Za-z0-9._ ()-]+/', '_', $name);
        $name = trim(ltrim($name, '.'), ' _');
        if (strlen($name) > 150) {
            $dot  = strrpos($name, '.');
            $ext  = ($dot && strlen($name) - $dot <= 10) ? substr($name, $dot) : '';
            $name = substr($name, 0, 150 - strlen($ext)).$ext;
        }
        return $name === '' ? 'upload' : $name;
    }

    public static function dir()
    {
        return \RoundSphere\Properties::getProperty('uploads_dir');
    }
}
