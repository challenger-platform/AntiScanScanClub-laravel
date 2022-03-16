<?php

namespace challengerplatform\AntiScanScanClub;

use Illuminate\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class AntiScanScanClub
{
    /**
     * @var string $defaultBlacklists
     */
    private $defaultBlacklists = "blacklists.json";

    /**
     * @var string $filterRules
     */
    private $filterRules = "filter_rules.json";

    /**
     * @var string $filterFiles
     */
    private $filterFiles = "filter_files.txt";

    /**
     * @var constant string REMOTE_REPO
     */
    private const REMOTE_REPO = "https://github.com/challenger-platform/AntiScanScanClub-laravel";

    /**
     * @var constant string FILTER_FILES_MD5
     */
    private const FILTER_FILES_MD5 = "05c2fe4cad6dc3ea1a3bf2becdb9153f";

    /**
     * AntiScanScanClub.
     *
     */
    public function __construct() {
    	$this->list = config('antiscanscanclub.list');
    	$this->options = config('antiscanscanclub.options');
    	$this->abort = ($this->options['return'] == NULL ? 403 : $this->options['return']);

    	$getBlacklists = $this->getBlacklists();
		$this->list_object = json_decode($getBlacklists, TRUE);
		if ($this->list_object === NULL) $this->purgeBlacklistsFile();
    }

    /**
	 * Get blacklists data
	 *
	 * @return string
	 *
	 * @throws \Exception
	*/
    private function getBlacklists() {
    	try {
            $get = Storage::get($this->list);
            return $get;
        } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException $e) {
            $this->purgeBlacklistsFile();
        } catch (\Exception $e) {
            throw new \Exception("Error while get blacklists File: " . $e->getMessage(), 1);
        }
    }

    /**
     * Search client IP in blacklists file
     *
     * @param string $clientIp the visitor client IP
     * @return bool/integer
    */
    private function searchIp($clientIp) {
    	try {
    		if (($key = array_search($clientIp, array_column($this->list_object, "client_ip"), TRUE)) !== FALSE) {
		    	return $key;
		    } else {
		    	return FALSE;
		    }
    	} catch(\Exception $e) {
    		return FALSE;
    	}
    }

    /**
     * Check whether the client IP has been blocked or not
     *
     * @param string $clientIp the visitor client IP
     * @return void/bool
    */
    public function checkIp($clientIp) {
    	if ($this->searchIp($clientIp) !== FALSE) {
			return abort($this->abort);
    	} else {
			return FALSE;
    	}
    }

    /**
     * Prevention of illegal input based on filter rules file
     *
     * @param array $data the request data
     * @param bool $blocker add client IP to blacklists if contains illegal input
     * @param $clientIp the visitor client IP
     * @return void/bool
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
    */
    public function filterInput($data = [], $blocker = FALSE, $clientIp) {
    	$filterRules = __DIR__ . "/" . $this->filterRules;
    	$getRule = @file_get_contents($filterRules);

    	if ($getRule === FALSE) {
    		throw new \Exception("Error Processing filter rules File!", TRUE);	
    	}

    	$objectRules = json_decode($getRule, TRUE)['filters'];

    	foreach ($data as $key => $value) {
	    	foreach ($objectRules as $key => $object) {
                if (is_array($value)) {
                    return $this->filterInput($value, $blocker, $clientIp);
                } else {
                    $filtered = preg_match("/" . $object['rule'] . "/", $value);
                    if ($filtered) break;
                }
            }
        }

		if ($filtered) {
			if ($blocker === TRUE) $this->addToBlacklisted($clientIp, $object['description'] . " (" . $value . ")");
			return abort($this->abort);
		}

    	return FALSE;
    }

    /**
     * Prevention of access to credentials and/ important files/path
     * e.g: wp-admin.php, .git/, backups.tar.gz, www.sql (see many more at filter_files.txt)
     *
     * @param array $data the request data
     * @param bool $blocker add client IP to blacklists if trying to credentials and/ important files/path
     * @param $clientIp the visitor client IP
     * @return void/bool
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
    */
    public function filterFile($url = NULL, $blocker = FALSE, $clientIp) {
        $filterFileFind = $this->filterFileFind($url);

        if ($filterFileFind === TRUE) {
            if ($blocker === TRUE) $this->addToBlacklisted($clientIp, "Trying to access " . $url);
            return abort($this->abort);
        } else {
            return FALSE;
        }
    }

    /**
     * Check whether the destination file and/ path is in the filter_files.txt
     *
     * @param string $file and/ path to check 
     * @return bool
    */
    private function filterFileFind($file) {
        $filterFiles = __DIR__ . "/" . $this->filterFiles;
        $getFile = @file_get_contents($filterFiles);

        if ($getFile === FALSE) {
            throw new \Exception("Error Processing filter Files File!", TRUE);  
        }

        $objectFiles = file($filterFiles);

        foreach ($objectFiles as $key => $value) {
            $list = trim($value);
            if (substr($file, 1) === trim($list)) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Add client IP to blacklists rule
     *
     * @param string $clientIp the visitor client IP
     * @param string $attack is attack type
     * @return bool
    */
    public function addToBlacklisted($clientIp, $attack = NULL) {
    	$add = $this->list_object;
    	$data = [
    		'client_ip' => $clientIp,
    		'attack_type' => ($attack = NULL ? "added manually" : $attack),
    		'date_time' => date('Y-m-d H:i:s')
    	];
    	array_push($add, $data);

    	return $this->writeToBlacklistsFile($add);
    }

    /**
     * Add file and/ path to filter_files.txt
     *
     * @param string $file and/ path
     * @return integer/bool
    */
    public function addToFilterFiles($file) {
        $filterFiles = __DIR__ . "/" . $this->filterFiles;
        $filterFileFind = $this->filterFileFind($file);

        if ($filterFileFind === FALSE) {
            return file_put_contents($filterFiles, $file, FILE_APPEND);
        } else {
            return FALSE;
        }
    }

	/**
     * Clean the client IP from blacklists
     *
     * @return array
    */
	public function cronBlacklistedRules() {
		foreach ($this->list_object as $key => $object) {
			$getDiffInHours = (int) round(abs(strtotime('now') - strtotime($object['time'])) / 3600, 0);
			if ($getDiffInHours >= $this->options['expired']) {
				return $this->removeFromBlacklists($object['client_ip']);
			}
		}
	}

    /**
     * Remove client IP from blacklists rule
     *
     * @param string $clientIp the visitor client IP
     * @return callable
    */
    public function removeFromBlacklists($clientIp) {
    	$searchIp = $this->searchIp($clientIp);
		if ($searchIp !== FALSE) {
	    	unset($this->list_object[$searchIp]);
	    }
	    return $this->writeToBlacklistsFile($this->list_object);
	}

	/**
     * Purge and/ clean all client IPs from blacklists
     *
     * @return callable
    */
    public function purgeBlacklistsFile() {
    	return $this->writeToBlacklistsFile([]);
    }

    /**
     * Write visitor data to blacklists file
     *
     * @param array $data the visitor data (such as client IP, attack type, etc)
     * @return bool
     *
     * @throws \Exception
    */
    private function writeToBlacklistsFile($data = []) {
    	$write = Storage::put(($this->list == NULL ? $this->defaultBlacklists : $this->list), json_encode($data, JSON_PRETTY_PRINT));

    	if ($write === FALSE) {
    		throw new \Exception("Error While writing to blacklists File!", TRUE);
    	} else {
    		return TRUE;
    	}
    }

    /**
     * Get all files in public path recursively
     *
     * @return array
     */
    private function getPublicFiles() {
        $getFiles = File::allFiles(public_path());
        $files = [];

        foreach ($getFiles as $key => $value) {
            $files[] = $value->getRelativePathname();
        }

        return $files;
    }

    /**
     * Get uri of all registered routes
     *
     * @return array
     */
    private function getAllRoutes() {
        $getRoutes = Route::getRoutes()->getIterator();
        $routes = [];

        foreach ($getRoutes as $key => $route) {
            $routes[] = $route->uri();
        }

        foreach ($routes as $key => $value) {
            if (preg_match("/\{.*?\}/", $value)) {
                unset($routes[$key]);
            }
        }

        return array_values($routes);
    }

    /**
     * Whitelisting all public files recursively
     *
     * @return array
     */
    public function whitelistPublicFiles() {
        $getPublicFiles = $this->getPublicFiles();
        $results = [];

        foreach ($getPublicFiles as $key => $file) {
            $whitelistFile = $this->whitelistFile($file);
            $results[] = [ $file => $whitelistFile ];
        }

        return $results;
    }

    /**
     * Whitelisting uri of all registered routes
     *
     * @return array
     */
    public function whitelistAllRoutes() {
        $getAllRoutes = $this->getAllRoutes();
        $results = [];

        foreach ($getAllRoutes as $key => $route) {
            $whitelistFile = $this->whitelistFile($route);
            $results[] = [ $route => $whitelistFile ];
        }

        return $results;
    }

    /**
     * Whitelisting credentials and/ important files/path
     *
     * @param string $search is the name of files/path do you want to whitelisted
     * @return bool
     */
    public function whitelistFile($search) {
        $filterFiles = __DIR__ . "/" . $this->filterFiles;
        $filterFile = file($filterFiles);
        $status = FALSE;

        foreach ($filterFile as $key => $value) {
            if (trim($value) === $search) {
                $offset = $key;
                $status = TRUE;
                break;
            }
        }

        if ($status !== FALSE) {
            unset($filterFile[$offset]);
            file_put_contents($filterFiles, join($filterFile));
        }

        return $status;
    }

    /**
    * MD5 checksum for local filter_files.txt
    *
    * @return bool
    */
    public function md5LocalFilterFiles() {
        $localFilterFiles = __DIR__ . "/" . $this->filterFiles;

        if (md5_file($localFilterFiles) === self::FILTER_FILES_MD5) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
    * Getting filter_files.txt from remote repository
    *
    * @return string
    */
    private function getRemoteFilterFiles() {
        $defaultFilterFiles = @file_get_contents(self::REMOTE_REPO . "/raw/master/src/" . $this->filterFiles);

        if ($defaultFilterFiles === FALSE) {
            throw new \Exception("Error While Getting default filter files from Repo", 1);
        }

        return $defaultFilterFiles;
    }

    /**
    * Restore filter_files.txt lists to default
    *
    * @return bool
    */
    public function restoreFilterFiles() {
        $remoteFilterFiles = $this->getRemoteFilterFiles();

        if ($this->md5LocalFilterFiles() === FALSE) {
            $write = file_put_contents(__DIR__ . "/" . $this->filterFiles, $remoteFilterFiles);
            if ($write === 84213 && $this->md5LocalFilterFiles() === TRUE) {
                return TRUE;
            } else {
                return FALSE;
            }
        } else {
            return TRUE;
        }
    }
}
