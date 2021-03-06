<?php

namespace App\Http\Controllers;

use Artisan;
use DirectoryIterator;

/**
 * Implements functionality related to backups
 *
 * @license MIT
 * @author Alexandros Gougousis
 */
class BackupController extends RootController
{

    /**
     * The directory where we store backup files
     *
     * @var string
     */
    protected $backupDirectory;

    public function __construct()
    {
        $this->backupDirectory = storage_path('backup');
    }

    /**
     * Returns a list of exiting backups
     *
     * @return Response
     */
    public function search()
    {
        $backup_list = $this->searchForBackups();
        return response()->json($backup_list, 200);
    }

    /**
     * Searches the backup directory and returns a list with backups found
     *
     * @return array
     */
    private function searchForBackups()
    {
        $backup_list = [];
        $dir = new DirectoryIterator($this->backupDirectory);

        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                // Add this backup to the list
                $backup_list[] = $this->extractBackupInfo($fileinfo);
            }
        }

        return $this->sortNewestToOldest($backup_list);
    }

    /**
     * Sort backup list from newest to oldest
     *
     * @param array $backup_list
     * @return array
     */
    private function sortNewestToOldest($backup_list)
    {
        usort($backup_list, function ($a, $b) {
            $date_a = strtotime($a['when']);
            $date_b = strtotime($b['when']);
            if ($date_a == $date_b) {
                return 0;
            }
            return ($date_a < $date_b) ? -1 : 1;
        });

        return $backup_list;
    }

    /**
     * Extracts file information from a DirectoryIterator item
     *
     * @param DirectoryIterator $file
     * @return array
     */
    private function extractBackupInfo(DirectoryIterator $file)
    {
        // Get the backup filename and size
        $basename = $file->getBasename('.gz');
        $filename = $file->getFilename();
        $filesize = $file->getSize();

        // Extract the backup date
        $fparts = explode('_', $basename);
        $time_parts = explode('-', $fparts[2]);
        $dt_string = $fparts[1]." ".implode(':', $time_parts);

        // Add this backup to the list
        return array(
            'when'  =>  $dt_string,
            'size'  =>  $filesize,
            'filename'  =>  $filename
        );
    }

    /**
     * Creates a new database backup of monitorable items
     *
     * @return Response
     */
    public function create()
    {
        $filename = 'backup_'.date("d-m-Y_H-i-s");
        try {
            Artisan::call('db:backup', [
                '--database'    =>'mysql',
                '--destination' =>'local',
                '--compression' =>'gzip',
                '--destinationPath' =>  $filename
            ]);
        } catch (Exception $ex) {
            $filepath = $this->backupDirectory."/$filename";
            if (file_exists()) {
                unlink($filepath);
            }
            $this->logEvent("Backup failed! ".$ex->getMessage(), "error");
            return response()->json(['errors' => array()])->setStatusCode(500, 'Backup failed! Please, check the error logs!');
        }

        return response()->json([])->setStatusCode(200, 'Backup created successfully!');
    }

    /**
     * Restores a backup
     *
     * @param String $filename The backup file name
     * @return Response
     */
    public function restore($filename)
    {
        $filepath = $this->backupDirectory."/$filename";
        if (!file_exists($filepath)) {
            return response()->json(['errors' => array()])->setStatusCode(404, 'Backup file were not found!');
        }

        try {
            Artisan::call('db:restore', [
                '--source'      =>  'local',
                '--sourcePath'  =>  $filename,
                '--database'    =>  'mysql',
                '--compression' =>  'gzip'
            ]);
        } catch (Exception $ex) {
            $this->logEvent("Database restoration failed! ".$ex->getMessage(), "error");
            return response()->json(['errors' => array()])->setStatusCode(500, 'Backup restoration failed! Please, check the error logs!');
        }

        return response()->json([])->setStatusCode(200, 'Backup restored successfully!');
    }

    /**
     * Deletes an existing backup
     *
     * @param String $filename The backup file name
     * @return Response
     */
    public function delete($filename)
    {
        $filepath = storage_path('backup')."/".$filename;
        if (!file_exists($filepath)) {
            return response()->json([])->setStatusCode(404, 'Backup file were not found');
        }
        unlink($filepath);
        return response()->json([])->setStatusCode(200, 'Backup deleted!');
    }
}
