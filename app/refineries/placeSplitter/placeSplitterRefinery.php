<?php
/* ----------------------------------------------------------------------
 * placeSplitterRefinery.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2013 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
 	require_once(__CA_LIB_DIR__.'/ca/Import/BaseRefinery.php');
 	require_once(__CA_LIB_DIR__.'/ca/Utils/DataMigrationUtils.php');
 
	class placeSplitterRefinery extends BaseRefinery {
		# -------------------------------------------------------
		private $opb_returns_multiple_values = true;
		# -------------------------------------------------------
		public function __construct() {
			$this->ops_name = 'placeSplitter';
			$this->ops_title = _t('Place splitter');
			$this->ops_description = _t('Provides several place-related import functions: splitting of multiple places in a string into individual values, mapping of type and relationship type for related places, building place hierarchies and merging place data with names.');
			
			parent::__construct();
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => true,
			);
		}
		# -------------------------------------------------------
		/**
		 *
		 */
		public function refine(&$pa_destination_data, $pa_group, $pa_item, $pa_source_data, $pa_options=null) {
			$this->opb_returns_multiple_values = true;
			$o_log = (isset($pa_options['log']) && is_object($pa_options['log'])) ? $pa_options['log'] : null;
			
			$va_group_dest = explode(".", $pa_group['destination']);
			$vs_terminal = array_pop($va_group_dest);
			$pm_value = $pa_source_data[$pa_item['source']];
			
			if (is_array($pm_value)) {
				$va_places = $pm_value;	// for input formats that support repeating values
			} else {
				if ($vs_delimiter = $pa_item['settings']['placeSplitter_delimiter']) {
					$va_places = explode($vs_delimiter, $pm_value);
				} else {
					$va_places = array($pm_value);
				}
			}
			
			$va_vals = array();
			$vn_c = 0;
			foreach($va_places as $vn_i => $vs_place) {
				if (!$vs_place = trim($vs_place)) { continue; }
				
				
				if($vs_terminal == 'name') {
					$this->opb_returns_multiple_values = false;
					return $vs_place;
				}
			
				if (in_array($vs_terminal, array('preferred_labels', 'nonpreferred_labels'))) {
					return array(0 => array('name' => $vs_place));	
				}
			
				// Set label
				$va_val = array('preferred_labels' => array('name' => $vs_place));
			
				// Set relationship type
				if (
					($vs_rel_type_opt = $pa_item['settings']['placeSplitter_relationshipType'])
				) {
					$va_val['_relationship_type'] = BaseRefinery::parsePlaceholder($vs_rel_type_opt, $pa_source_data, $pa_item, $vs_delimiter, $vn_c);
				}
				
				if ((!isset($va_val['_relationship_type']) || !$va_val['_relationship_type']) && ($vs_rel_type_opt = $pa_item['settings']['placeSplitter_relationshipTypeDefault'])) {
					$va_val['_relationship_type'] = BaseRefinery::parsePlaceholder($vs_rel_type_opt, $pa_source_data, $pa_item, $vs_delimiter, $vn_c);
				}
				
				if ((!isset($va_val['_relationship_type']) || !$va_val['_relationship_type']) && $o_log) {
					$o_log->logWarn(_t('[placeSplitterRefinery] No relationship type is set for place %1', $vs_place));
				}
			
				// Set place_type
				if (
					($vs_type_opt = $pa_item['settings']['placeSplitter_placeType'])
				) {
					$va_val['_type'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $vs_delimiter, $vn_c);
				}
				
				if((!isset($va_val['_type']) || !$va_val['_type']) && ($vs_type_opt = $pa_item['settings']['placeSplitter_placeTypeDefault'])) {
					$va_val['_type'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $vs_delimiter, $vn_c);
				}
				
				if ((!isset($va_val['_type']) || !$va_val['_type']) && $o_log) {
					$o_log->logWarn(_t('[placeSplitterRefinery] No place type is set for place %1', $vs_place));
				}
				
				// Set place hierarchy
				if ($vs_hierarchy = $pa_item['settings']['placeSplitter_hierarchy']) {
					$vn_hierarchy_id = caGetListItemID('place_hierarchies', $vs_hierarchy);
				} else {
					// Default to first place hierarchy
					$t_list = new ca_lists();
					$va_hierarchy_ids = $t_list->getItemsForList('place_hierarchies', array('idsOnly' => true));
					$vn_hierarchy_id = array_shift($va_hierarchy_ids);
				}
				if (!$vn_hierarchy_id) {
					if ($o_log) { $o_log->logError(_t('[placeSplitterRefinery] No place hierarchies are defined for %1', $vs_place)); }
					return array();
				}
				$t_place = new ca_places();
				$t_place->load(array('parent_id' => null, 'hierarchy_id' => $vn_hierarchy_id));
				$va_val['_parent_id'] = $t_place->getPrimaryKey();
				
				if ($o_log && !$va_val['_parent_id']) { $o_log->logError(_t('[placeSplitterRefinery] No parent found or place %1 in hierarchy %2', $vs_place, $vs_hierarchy)); return array(); }
				
				// Set attributes
				if (is_array($pa_item['settings']['placeSplitter_attributes'])) {
					$va_attr_vals = array();
					foreach($pa_item['settings']['placeSplitter_attributes'] as $vs_element_code => $va_attrs) {
						if(is_array($va_attrs)) {
							foreach($va_attrs as $vs_k => $vs_v) {
								// BaseRefinery::parsePlaceholder may return an array if the input format supports repeated values (as XML does)
								// DataMigrationUtils::getPlaceID(), which ca_data_importers::importDataFromSource() uses to create related places
								// only supports non-repeating attribute values, so we join any values here and call it a day.
								$va_attr_vals[$vs_element_code][$vs_k] = (is_array($vm_v = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $vs_delimiter, $vn_c))) ? join(" ", $vm_v) : $vm_v;
							}
						} else {
							$va_attr_vals[$vs_element_code][$vs_element_code] = (is_array($vm_v = BaseRefinery::parsePlaceholder($va_attrs, $pa_source_data, $pa_item, $vs_delimiter, $vn_c))) ? join(" ", $vm_v) : $vm_v;
						}
					}
					$va_val = array_merge($va_val, $va_attr_vals);
				}
				
				$va_vals[] = $va_val;
				$vn_c++;
			}
			
			return $va_vals;
		}
		# -------------------------------------------------------	
		/**
		 * placeSplitter returns multiple values
		 *
		 * @return bool
		 */
		public function returnsMultipleValues() {
			return $this->opb_returns_multiple_values;
		}
		# -------------------------------------------------------
	}
	
	 BaseRefinery::$s_refinery_settings['placeSplitter'] = array(		
			'placeSplitter_delimiter' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_SELECT,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Delimiter'),
				'description' => _t('Sets the value of the delimiter to break on, separating data source values.')
			),
			'placeSplitter_relationshipType' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_SELECT,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Relationship type'),
				'description' => _t('Accepts a constant type code for the relationship type or a reference to the location in the data source where the type can be found.')
			),
			'placeSplitter_placeType' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_SELECT,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Place type'),
				'description' => _t('Accepts a constant list item idno from the list place_types or a reference to the location in the data source where the type can be found.')
			),
			'placeSplitter_attributes' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_SELECT,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Attributes'),
				'description' => _t('Sets or maps metadata for the place record by referencing the metadataElement code and the location in the data source where the data values can be found.')
			),
			'placeSplitter_hierarchy' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_SELECT,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Hierarchy'),
				'description' => _t('Identifies the root node of the place hierarchy to add places to.')
			),
			'placeSplitter_relationshipTypeDefault' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_FIELD,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Relationship type default'),
				'description' => _t('Sets the default relationship type that will be used if none are defined or if the data source values do not match any values in the CollectiveAccess system')
			),
			'placeSplitter_placeTypeDefault' => array(
				'formatType' => FT_TEXT,
				'displayType' => DT_FIELD,
				'width' => 10, 'height' => 1,
				'takesLocale' => false,
				'default' => '',
				'label' => _t('Place type default'),
				'description' => _t('Sets the default place type that will be used if none are defined or if the data source values do not match any values in the CollectiveAccess list place_types')
			)
		);
?>