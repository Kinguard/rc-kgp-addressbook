<?php

function l($log)
{
	rcube::write_log('errors', $log);
}


class opi_addressbook_backend extends rcube_addressbook
{
	public $primary_key = 'ID';
	public $readonly = true;
	public $groups = false;

	private $filter;
	private $result;
	private $name;
	private $db;
	private $user;

	public function __construct($name, $user)
	{
		include "/usr/share/owncloud/config/config.php";

		$this->name = $name;
		$this->user = $user;
		$this->ready = false;
		$this->db = mysqli_connect( $CONFIG["dbhost"], $CONFIG["dbuser"], $CONFIG["dbpassword"], $CONFIG["dbname"]);
		if( $db === False )
		{
			rcube::write_log('errors', "Could not connect: ".mysql_error());
			return;
		}

		mysqli_set_charset($this->db, "utf8");

		$this->ready = true;
		$this->name = $name;
	}

	public function get_name()
	{
		return $this->name;
	}

	public function set_search_set($filter)
	{
		l("Set filter");
		$this->filter = $filter;
	}

	public function get_search_set()
	{
		l("Get search set");
		return $this->filter;
	}

	public function reset()
	{
		l("Reset");
		$this->result = null;
		$this->filter = null;
	}

	private function cmp($a, $b)
	{
		return strcasecmp( $a["name"], $b["name"]);
	}


	private function parseline($key, $line)
	{
		if( ! isset($line["EMAIL"]) || !isset($line["FN"]))
		{
			return false;
		}

		$ret = array('ID' => $key, 'name' => $line["FN"], 'email' => $line["EMAIL"]);

		if( isset($line["N"]) )
		{
			$n = explode( ";", $line["N"] );
			$ret["surname"] = $n[0];
			$ret["firstname"] = $n[1];
		}

		return $ret;
	}

	private function subset($set)
	{
		$offset = ($this->list_page-1) * $this->page_size;

		$set = array_slice ( $set, $offset, $this->page_size,  false );

		return $set;
	}

	private function get($ids = null)
	{
		$query = sprintf( "select contactid,name, value from oc_contacts_cards_properties where userid='%s'",
			$this->user);

		if( $ids )
		{

			$query .= " and contactid in ('";

			if( gettype($ids) == "array" )
			{
				$query .= implode("','", $ids);
			}
			else if( gettype($ids) == "string")
			{
				$query .= "$ids";
			}

			$query .= "')";
		}

		$result = mysqli_query( $this->db, $query );

		if( !$result )
		{
			rcube::write_log('errors', "Query failed: " . mysql_error());
			return false;
		}

		$data = array();
		while ($line = mysqli_fetch_array($result, MYSQL_NUM))
		{
			if( $line[1] == "EMAIL" )
			{
				$data[$line[0]][$line[1]][]=$line[2];
			}
			else
			{
				$data[$line[0]][$line[1]] = $line[2];
			}
		}

		$set = new rcube_result_set(0, ($this->list_page-1) * $this->page_size);

		foreach( $data as $key => $line )
		{
			if( $ret = $this->parseline($key, $line) )
			{
				$set->add( $ret );
			}
		}

		usort($set->records, array($this, "cmp"));


		$set->count = count( $set->records );

		return $set;

	}

	public function list_records($cols=null, $subset=0)
	{
		l("list records");
		$this->result = $this->get();

		$this->filtersearch();

		$this->result->records = $this->subset($this->result->records);

		return $this->result;
	}

	private function filtersearch()
	{
		if( gettype($this->filter) == "array" && count($this->filter) ==3 )
		{
			if( $this->fiter[0] == 0 )
			{
				$set = $this->partialsearch($this->filter[1],$this->filter[2]);
				$this->result->records = $set->records;
			}
		}
	}

	private function partialsearch($fields, $value)
	{

		$set = $this->get();
		$preg = "/.*".$value.".*/i";
		foreach( $set->records as $i => $v)
		{
			$found = false;
			foreach( $v as $k => $w )
			{
				if( $fields == "*" || in_array( $k, $fields) )
				{
					if( gettype( $w ) == "array" )
					{
						foreach( $w as $aw )
						{
							if( preg_match( $preg, $aw) )
							{
								$found = true;
								break;
							}
						}
					}
					else
					{
						if( preg_match( $preg, $w) )
						{
							$found = true;
							break;
						}
					}
				}
			}
			if( ! $found )
			{
				unset($set->records[$i]);
			}
		}

		$set->records = array_values( $set->records);

		$set->count = count( $set->records );
		//print_r($set->records);

		return $set;
	}
/*
	Use search_Set to save search
	Support at least variable fields and *
	Support at least mode 0 partial search *sss*
*/
	public function search($fields, $value, $mode=0, $select=true, $nocount=false, $required=array())
	{

		if( $mode == 0 )
		{
			$this->set_search_set( array( 0, $fields, $value) );
			$set = $this->partialsearch($fields, $value);

			if( $select )
			{
				$this->result = $set;
			}
			else
			{
				$this->result = new rcube_result_set( $set->count, ($this->list_page-1) * $this->page_size);
			}

			return $this->result;
		}
		l("Normal search");
		// no search implemented, just list all records
		return $this->list_records();
	}

	public function count()
	{
		$set = $this->get();
		return new rcube_result_set( $set->count, ($this->list_page-1) * $this->page_size);
	}

	public function get_result()
	{
		//l("Get result");
		return $this->result;
	}

	 public function get_record($id, $assoc=false)
	{
		$this->result = $this->get($id);

		return $assoc ? $this->result->records[0] : $this->result;
	}

	function close()
	{
		mysqli_close( $this->db );
	}
}
