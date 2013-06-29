<?php
	/**
	 * @package extensions/pager/lib
	 */
	/**
	 * This a simple class that can be used to implement standardized pagination for
	 * extensions and other Symphony pages. The following sample can illustrate how
	 * this class may be used:
	 * 
	 *  $totalRecords = 50;     // Typically the value of a MySQL SELECT COUNT(1) query against a data table.
 	 *  $perPage      = 15;     // Usually a call to `Symphony::Configuration()->get('pagination_maximum_rows', 'symphony')`.
	 *  $pageKey      = 'page'; // For instance: http://www.mysite.com/articles/?page=3
	 * 
 	 * $Pager = Pager::factory($totalRecords, $perPage, $pageKey);
	 * 
 	 * $results = Symphony::Database()->fetch("
	 * 	SELECT
	 * 		`id`,
	 * 		`title` 
	 * 	FROM `my_table`
	 * 	ORDER BY `id` DESC '.$Pager->getLimit(true)
	 *  ");
	 * 
 	 * // more code here to display page data
	 * 
 	 * $this->Form->appendChild($Pager->save()); // If being invoked through a backend page
	 */
	class Pager {
		/**
		 * Represents the total number of records for the particular data set. This is used to 
		 * calculate the number of total pages to navigate through.
		 * @var integer
		 * @access private
		 */
		private $totalRecords;

		/**
		 * Specifies how many records are to be displayed per page.
		 * @var integer
		 * @access private
		 */
		private $perPage;

		/**
		 * The $_REQUEST index that is used to pass the current page number between requests.
		 * @var string
		 * @access private
		 */
		private $pageKey;

		/**
		 * This is the total number of pages. Calculated by `ceil($this->totalRecords / $this->perPage);`
		 * @var integer
		 * @access private
		 */
		private $totalPages;

		/**
		 * Determines the currently viewed page number. Defaults to page 1 if not specified.
		 * @var integer
		 * @access private
		 */
		private $currentPage;

		/**
		 * The constructor for class Pager. This instantiates the class and assigns default property
		 * values.
		 *
		 * @param Integer $totalRecords
		 *  The total number of records for this data set.
		 * @param Integer $perPage
		 *  Specifieds how many records are to be displayed per page.
		 * @param String $pageKey
		 *  The $_REQUEST index that is used to pass the current page number between requests.
		 * @access public
		 */
		public function __construct($totalRecords, $perPage, $pageKey) {
			$this->totalRecords = (int) $totalRecords;
			$this->perPage      = (int) $perPage;
			$this->pageKey      = $pageKey;
			$this->totalPages   = null;
			$this->currentPage  = null;
		}

		/**
		 * Factory method which returns an instance of class Pager. Can be used if multiple
		 * instances of page splitting are required.
		 *
		 * @param Integer $totalRecords
		 *  The total number of records for this data set.
		 * @param Integer $perPage
		 *  Specifieds how many records are to be displayed per page.
		 * @param String $pageKey
		 *  The $_REQUEST index that is used to pass the current page number between requests.
		 * @access public
		 * @return Object Pager
		 */
		static public function factory($totalRecords, $perPage, $pageKey) {
			return new self($totalRecords, $perPage, $pageKey);
		}

		/**
		 * Returns the current page number based on the value of `$_REQUEST[Pager::$pageKey];`. If
		 * called multiple times it returns the value returned from the initial invocation. Does a 
		 * bit of boundary checking as well to ensure the current page stays within the appropriate
		 * min/max range.
		 *
		 * @access public
		 * @return integer
		 */
		public function getCurrentPage() {
			if(false === is_null($this->currentPage))
				return $this->currentPage;

			$currentPage = isset($_REQUEST[$this->pageKey]) ? (int) $_REQUEST[$this->pageKey] : 1;

			if($currentPage > $this->getTotalPages())
				return $this->currentPage = $this->getTotalPages();

			if($currentPage < 1)
				return $this->currentPage = 1;

			return $this->currentPage = $currentPage;
		}

		/**
		 * Returns the total number of pages for this instance. If called multiple times it returns 
		 * the value returned from the initial invocation. Calculated by:
		 *
		 * `ceil($this->totalRecords / $this->perPage);`
		 * 
		 * @access public
		 * @return integer
		 */
		public function getTotalPages() {
			if(false === is_null($this->totalPages))
				return $this->totalPages;
			return $this->totalPages = ceil($this->totalRecords/$this->perPage);
		}

		/**
		 * Returns the starting offset for the MySQL LIMIT clause. This is determined by examining
		 * the value of `Pager::getCurrentPage()` and multiplying it against `Pager::$perPage`.
		 *
		 * @access public
		 * @return integer
		 */
		public function getStart() {
			$start = 0;
			if($this->getCurrentPage() > 1)
				return ceil(($this->getCurrentPage() - 1)*$this->perPage);
			return $start;
		}

		/**
		 * Returns an array containing the offset and limit range for the current page. This can be 
		 * used to restrict the position of records displayed on the current page.
		 *
		 * @param boolean $toString
		 *  If set to TRUE, this method will return a string using the following format as 
		 *  outlined by the MySQL LIMIT clause syntax: LIMIT {offset}, {limit}
		 * @access public
		 * @return String|Array
		 */
		public function getLimit($toString = false) {
			if($toString)
				return 'LIMIT '.$this->getStart().", {$this->perPage}";
			return array(
				$this->getStart(),
				$this->perPage
			);
		}

		/**
		 * When invoked, this method compiles all calculated paging information and returns an
		 * XMLElement instance that represents a standardized Symphony page bar.
		 *
		 * @access public
		 * @return XMLElement Object
		 */
		public function save() {
			$ul = new XMLElement('ul');
			if($this->getTotalPages() > 1) {
				$ul->setAttribute('class', 'page');

				$li = new XMLElement('li');
				if($this->getCurrentPage() > 1) {
					$li->appendChild(Widget::Anchor(__('First'), Administration::instance()->getCurrentPageURL()."?{$this->pageKey}=1"));
				} else {
					$li->setValue(__('First'));
				}
				$ul->appendChild($li);

				$li = new XMLElement('li');
				if($this->getCurrentPage() > 1) {
					$li->appendChild(Widget::Anchor(__('&larr; Previous'), Administration::instance()->getCurrentPageURL(). "?{$this->pageKey}=".($this->getCurrentPage()-1)));
				} else {
					$li->setValue(__('&larr; Previous'));
				}
				$ul->appendChild($li);

				$li = new XMLElement('li', __('Page %1$s of %2$s', array($this->getCurrentPage(), $this->getTotalPages())));
				$li->setAttribute('title', __('Viewing %1$s - %2$s of %3$s entries', array(
					$this->getStart() + 1,
					($this->getCurrentPage() != $this->getTotalPages()) ? $this->getCurrentPage()*$this->perPage : $this->totalRecords,
					$this->totalRecords
				)));
				$ul->appendChild($li);

				$li = new XMLElement('li');
				if($this->getCurrentPage() < $this->getTotalPages()) {
					$li->appendChild(Widget::Anchor(__('Next &rarr;'), Administration::instance()->getCurrentPageURL()."?{$this->pageKey}=".($this->getCurrentPage()+1)));
				} else {
					$li->setValue(__('Next &rarr;'));
				}
				$ul->appendChild($li);

				$li = new XMLElement('li');
				if($this->getCurrentPage() < $this->totalPages) {
					$li->appendChild(Widget::Anchor(__('Last'), Administration::instance()->getCurrentPageURL()."?{$this->pageKey}=".$this->totalPages));
				} else {
					$li->setValue(__('Last'));
				}
				$ul->appendChild($li);
			}
			return $ul;
		}

		/**
		 * Magic method which returns the XML output generated by `Pager::save()`. Invoked when an instance
		 * of this class is used as a string.
		 *
		 * @access public
		 * @return string
		 */
		public function __toString() {
			return $this->save()->generate();
		}
	}