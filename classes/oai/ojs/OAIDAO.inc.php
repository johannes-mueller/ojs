<?php

/**
 * @file classes/oai/ojs/OAIDAO.inc.php
 *
 * Copyright (c) 2003-2012 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OAIDAO
 * @ingroup oai_ojs
 * @see OAI
 *
 * @brief DAO operations for the OJS OAI interface.
 */

import('lib.pkp.classes.oai.PKPOAIDAO');
import('classes.issue.Issue');

class OAIDAO extends PKPOAIDAO {

 	/** Helper DAOs */
 	var $journalDao;
 	var $sectionDao;
	var $publishedArticleDao;
	var $articleGalleyDao;
	var $issueDao;
 	var $authorDao;
 	var $suppFileDao;
 	var $journalSettingsDao;

 	var $journalCache;
	var $sectionCache;
	var $issueCache;

 	/**
	 * Constructor.
	 */
	function OAIDAO() {
		parent::PKPOAIDAO();
		$this->journalDao =& DAORegistry::getDAO('JournalDAO');
		$this->sectionDao =& DAORegistry::getDAO('SectionDAO');
		$this->publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$this->articleGalleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$this->issueDao =& DAORegistry::getDAO('IssueDAO');
		$this->authorDao =& DAORegistry::getDAO('AuthorDAO');
		$this->suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$this->journalSettingsDao =& DAORegistry::getDAO('JournalSettingsDAO');

		$this->journalCache = array();
		$this->sectionCache = array();
	}

	/**
	 * @see lib/pkp/classes/oai/PKPOAIDAO::getEarliestDatestamp()
	 */
	function getEarliestDatestamp($setIds = array()) {
		return parent::getEarliestDatestamp('SELECT	MIN(COALESCE(at.date_deleted, a.last_modified))', $setIds);
	}

	/**
	 * Cached function to get a journal
	 * @param $journalId int
	 * @return object
	 */
	function &getJournal($journalId) {
		if (!isset($this->journalCache[$journalId])) {
			$this->journalCache[$journalId] =& $this->journalDao->getById($journalId);
		}
		return $this->journalCache[$journalId];
	}

	/**
	 * Cached function to get an issue
	 * @param $issueId int
	 * @return object
	 */
	function &getIssue($issueId) {
		if (!isset($this->issueCache[$issueId])) {
			$this->issueCache[$issueId] =& $this->issueDao->getIssueById($issueId);
		}
		return $this->issueCache[$issueId];
	}

	/**
	 * Cached function to get a journal section
	 * @param $sectionId int
	 * @return object
	 */
	function &getSection($sectionId) {
		if (!isset($this->sectionCache[$sectionId])) {
			$this->sectionCache[$sectionId] =& $this->sectionDao->getSection($sectionId);
		}
		return $this->sectionCache[$sectionId];
	}


	//
	// Sets
	//
	/**
	 * Return hierarchy of OAI sets (journals plus journal sections).
	 * @param $journalId int
	 * @param $offset int
	 * @param $total int
	 * @return array OAISet
	 */
	function &getJournalSets($journalId, $offset, $limit, &$total) {
		if (isset($journalId)) {
			$journals = array($this->journalDao->getById($journalId));
		} else {
			$journals =& $this->journalDao->getJournals();
			$journals =& $journals->toArray();
		}

		// FIXME Set descriptions
		$sets = array();
		foreach ($journals as $journal) {
			$title = $journal->getLocalizedTitle();
			$abbrev = $journal->getPath();
			array_push($sets, new OAISet(urlencode($abbrev), $title, ''));

			$articleTombstoneDao =& DAORegistry::getDAO('ArticleTombstoneDAO');
			$articleTombstoneSets = $articleTombstoneDao->getSets($journal->getId());

			$sections =& $this->sectionDao->getJournalSections($journal->getId());
			foreach ($sections->toArray() as $section) {
				if (array_key_exists(urlencode($abbrev) . ':' . urlencode($section->getLocalizedAbbrev()), $articleTombstoneSets)) {
					unset($articleTombstoneSets[urlencode($abbrev) . ':' . urlencode($section->getLocalizedAbbrev())]);
				}
				array_push($sets, new OAISet(urlencode($abbrev) . ':' . urlencode($section->getLocalizedAbbrev()), $section->getLocalizedTitle(), ''));
			}
			foreach ($articleTombstoneSets as $articleTombstoneSetSpec => $articleTombstoneSetName) {
				array_push($sets, new OAISet($articleTombstoneSetSpec, $articleTombstoneSetName, ''));
			}
		}

		HookRegistry::call('OAIDAO::getJournalSets', array(&$this, $journalId, $offset, $limit, $total, &$sets));

		$total = count($sets);
		$sets = array_slice($sets, $offset, $limit);

		return $sets;
	}

	/**
	 * Return the journal ID and section ID corresponding to a journal/section pairing.
	 * @param $journalSpec string
	 * @param $sectionSpec string
	 * @param $restrictJournalId int
	 * @return array (int, int)
	 */
	function getSetJournalSectionId($journalSpec, $sectionSpec, $restrictJournalId = null) {
		$journalId = null;

		$journal =& $this->journalDao->getJournalByPath($journalSpec);
		if (!isset($journal) || (isset($restrictJournalId) && $journal->getId() != $restrictJournalId)) {
			return array(0, 0);
		}

		$journalId = $journal->getId();
		$sectionId = null;

		if (isset($sectionSpec)) {
			$section =& $this->sectionDao->getSectionByAbbrev($sectionSpec, $journal->getId());
			if (isset($section)) {
				$sectionId = $section->getId();
			} else {
				$sectionId = 0;
			}
		}

		return array($journalId, $sectionId);
	}

	//
	// Protected methods.
	//
	/**
	 * @see lib/pkp/classes/oai/PKPOAIDAO::getRecordSelectStatement()
	 */
	function getRecordSelectStatement() {
		return 'SELECT	COALESCE(at.date_deleted, a.last_modified) AS last_modified,
			COALESCE(a.article_id, at.article_id) AS article_id,
			COALESCE(j.journal_id, at.journal_id) AS journal_id,
			COALESCE(at.section_id, s.section_id) AS section_id,
			i.issue_id,
			at.tombstone_id,
			at.set_spec,
			at.oai_identifier';
	}

	/**
	 * @see lib/pkp/classes/oai/PKPOAIDAO::getRecordJoinClause()
	 */
	function getRecordJoinClause($articleId = null, $setIds = array(), $set = null) {
		list($journalId, $sectionId) = $setIds;
		return 'LEFT JOIN published_articles pa ON (m.i=0' . (isset($articleId) ? ' AND pa.article_id = ?' : '') . ')
			LEFT JOIN articles a ON (a.article_id = pa.article_id' . (isset($journalId) ? ' AND a.journal_id = ?' : '') . (isset($sectionId) ? ' AND a.section_id = ?' : '') .')
			LEFT JOIN issues i ON (i.issue_id = pa.issue_id)
			LEFT JOIN sections s ON (s.section_id = a.section_id)
			LEFT JOIN journals j ON (j.journal_id = a.journal_id)
			LEFT JOIN article_tombstones at ON (m.i = 1' . (isset($articleId) ? ' AND at.article_id = ?' : '') . (isset($journalId) ? ' AND at.journal_id = ?' : '') . (isset($sectionId) && $sectionId != 0 ? ' AND at.section_id = ?' : '') . (isset($set) ? ' AND at.set_spec = ?' : '') .')';
	}

	/**
	 * @see lib/pkp/classes/oai/PKPOAIDAO::getAccessibleRecordWhereClause()
	 */
	function getAccessibleRecordWhereClause() {
		return 'WHERE ((s.section_id IS NOT NULL AND i.published = 1 AND j.enabled = 1 AND a.status <> ' . STATUS_ARCHIVED . ') OR at.article_id IS NOT NULL)';
	}

	/**
	 * @see lib/pkp/classes/oai/PKPOAIDAO::getDateRangeWhereClause()
	 */
	function getDateRangeWhereClause($from, $until) {
		return (isset($from) ? ' AND ((at.date_deleted IS NOT NULL AND at.date_deleted >= '. $this->datetimeToDB($from) .') OR (at.date_deleted IS NULL AND a.last_modified >= ' . $this->datetimeToDB($from) .'))' : '')
			. (isset($until) ? ' AND ((at.date_deleted IS NOT NULL AND at.date_deleted <= ' .$this->datetimeToDB($until) .') OR (at.date_deleted IS NULL AND a.last_modified <= ' . $this->datetimeToDB($until) .'))' : '')
			. ' ORDER BY journal_id';
	}

	/**
	 * @see lib/pkp/classes/oai/PKPOAIDAO::setOAIData()
	 */
	function &setOAIData(&$record, &$row, $isRecord = true) {
		$journal =& $this->getJournal($row['journal_id']);
		$section =& $this->getSection($row['section_id']);
		$articleId = $row['article_id'];

		$record->identifier = $this->oai->articleIdToIdentifier($articleId);
		$record->sets = array(urlencode($journal->getPath()) . ':' . urlencode($section->getLocalizedAbbrev()));

		if ($isRecord) {
			$publishedArticle =& $this->publishedArticleDao->getPublishedArticleByArticleId($articleId);
			$issue =& $this->getIssue($row['issue_id']);
			$galleys =& $this->articleGalleyDao->getGalleysByArticle($articleId);

			$record->setData('article', $publishedArticle);
			$record->setData('journal', $journal);
			$record->setData('section', $section);
			$record->setData('issue', $issue);
			$record->setData('galleys', $galleys);
		}

		return $record;
	}
}

?>
