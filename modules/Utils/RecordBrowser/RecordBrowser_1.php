<?php
/**
 * RecordBrowser class.
 *
 * @author Arkadiusz Bisaga <abisaga@telaxus.com>
 * @copyright Copyright &copy; 2006, Telaxus LLC
 * @version 0.99
 * @package tcms-extra
 */

defined("_VALID_ACCESS") || die();

class Utils_RecordBrowser extends Module {
	private $table_rows = array();
	private $lang;
	private $tab;
	private $browse_mode;
	private $display_callback_table = array();
	private $QFfield_callback_table = array();
	private $requires = array();
	private $recent = 0;
	private $mode = 'view';
	private $caption = '';
	private $icon = '';
	private $favorites = false;
	private $full_history = true;
	private $action = 'Browsing';
	private $crits = array();
	private $access_callback;
	private $noneditable_fields = array();
	private $add_button = null;
	private $changed_view = false;
	private $is_on_main_page = false;
	private $custom_defaults = array();
	private $add_in_table = false;
	private $custom_filters = array();
	private $filter_field;
	private $default_order = array();
	private $cut = array();
	private $more_table_properties = array();
	public static $admin_filter = '';
	public static $tab_param = '';
	public static $clone_result = null;
	public static $clone_tab = null;
	public $record;
	public $adv_search = false;
	private $col_order = array();
	private $advanced = array();

	public function get_display_method($ar) {
		return isset($this->display_callback_table[$ar])?$this->display_callback_table[$ar]:null;
	}

	public function set_cut_lengths($ar) {
		$this->cut = $ar;
	}

	public function set_table_column_order($arg) {
		$this->col_order = $arg;
	}

	public function get_val($field, $record, $id, $links_not_recommended = false, $args = null) {
		return Utils_RecordBrowserCommon::get_val($this->tab, $field, $record, $id, $links_not_recommended, $args);
	}

	public function set_button($arg){
		$this->add_button = $arg;
	}

	public function set_header_properties($ar) {
		$this->more_table_properties = $ar;
	}

	public function get_access($action, $param=null){
		return Utils_RecordBrowserCommon::get_access($this->tab, $action, $param);
	}

	public function construct($tab = null) {
		$this->tab = $tab;
		Utils_RecordBrowserCommon::check_table_name($tab);
	}

	public function init($admin=false) {
		if (!isset($this->lang)) $this->lang = $this->init_module('Base/Lang');
		$params = DB::GetRow('SELECT caption, icon, recent, favorites, full_history FROM recordbrowser_table_properties WHERE tab=%s', array($this->tab));
		if ($params==false) trigger_error('There is no such recordSet as '.$this->tab.'.', E_USER_ERROR);
		list($this->caption,$this->icon,$this->recent,$this->favorites,$this->full_history) = $params;
		$cid = Utils_WatchdogCommon::get_category_id($this->tab);
		$this->watchdog = ($cid!==false && $cid!==false);

		//If Caption or icon not specified assign default values
		if ($this->caption=='') $this->caption='Record Browser';
		if ($this->icon=='') $this->icon = Base_ThemeCommon::get_template_filename('Base_ActionBar','icons/settings.png');
		$this->icon = Base_ThemeCommon::get_template_dir().$this->icon;

		$this->table_rows = Utils_RecordBrowserCommon::init($this->tab, $admin);
		$this->requires = array();
		$this->display_callback_table = array();
		$this->QFfield_callback_table = array();
		$ret = DB::Execute('SELECT * FROM '.$this->tab.'_callback');
		while ($row = $ret->FetchRow())
			if ($row['freezed']==1) $this->display_callback_table[$row['field']] = array($row['module'], $row['func']);
			else $this->QFfield_callback_table[$row['field']] = array($row['module'], $row['func']);
	}
	// BODY //////////////////////////////////////////////////////////////////////////////////////////////////////
	public function body($def_order=array(), $crits=array()) {
		$this->init();
		if (self::$clone_result!==null) {
			if (is_numeric(self::$clone_result)) $this->navigate('view_entry', 'view', self::$clone_result);
			self::$clone_result = null;
		}
		if ($this->get_access('browse')===false) {
			print($this->lang->t('You are not authorised to browse this data.'));
			return;
		}
		$this->is_on_main_page = true;
		if ($this->get_access('add')!==false)
			Base_ActionBarCommon::add('add','New', $this->create_callback_href(array($this,'navigate'),array('view_entry', 'add', null, $this->custom_defaults)));

		$filters = $this->show_filters();

		if (isset($this->filter_field)) {
			CRM_FiltersCommon::add_action_bar_icon();
			$ff = explode(',',trim(CRM_FiltersCommon::get(),'()'));
			$ff[] = '';
			$this->crits[$this->filter_field] = $ff;
		}
		$this->crits = $this->crits+$crits;
		ob_start();
		$this->show_data($this->crits, array(), array_merge($def_order, $this->default_order));
		$table = ob_get_contents();
		ob_end_clean();

		$theme = $this->init_module('Base/Theme');
		$theme->assign('filters', $filters);
		$theme->assign('table', $table);
		$theme->assign('caption', $this->lang->t($this->caption).' - '.$this->lang->t(ucfirst($this->browse_mode)));
		$theme->assign('icon', $this->icon);
		$theme->display('Browsing_records');
	}
	public function switch_view($mode){
		$this->browse_mode = $mode;
		$this->changed_view = true;
		$this->set_module_variable('browse_mode', $mode);
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	public function show_filters($filters_set = array(), $f_id='') {
		if ($this->get_access('browse')===false) {
			return;
		}
		$ret = DB::Execute('SELECT field FROM '.$this->tab.'_field WHERE filter=1');
		$filters_all = array();
		while($row = $ret->FetchRow())
			if (!isset($filters_set[$row['field']]) || $filters_set[$row['field']]) {
				$filters_all[] = $row['field'];
				if (isset($filters_set[$row['field']])) unset($filters_set[$row['field']]);
			}
		foreach($filters_set as $k=>$v)
			if ($v) $filters_all[] = $k;
		if (empty($filters_all)) {
			$this->crits = array();
			return '';
		}

		$form = $this->init_module('Libs/QuickForm', null, $this->tab.'filters');
		$filters = array();
		foreach ($filters_all as $filter) {
			$filter_id = strtolower(str_replace(' ','_',$filter));
			if (isset($this->custom_filters[$filter_id])) {
				$f = $this->custom_filters[$filter_id];
				if (!isset($f['label'])) $f['label'] = $filter;
				if (!isset($f['args'])) $f['args'] = null;
				$form->addElement($f['type'], $filter_id, $f['label'], $f['args']);
				$filters[] = $filter_id;
				continue;
			}
			$arr = array();
			if (!isset($this->QFfield_callback_table[$filter]) && ($this->table_rows[$filter]['type'] == 'select' || $this->table_rows[$filter]['type'] == 'multiselect')) {
				$param = explode(';',$this->table_rows[$filter]['param']);
				list($tab, $col) = explode('::',$param[0]);
				if ($tab=='__COMMON__') {
					$arr = array_merge($arr, Utils_CommonDataCommon::get_translated_array($col, true));
				} else {
					Utils_RecordBrowserCommon::check_table_name($tab);
					if (isset($this->table_rows[$col])) $col = $this->table_rows[$col]['id'];
					$ret2 = Utils_RecordBrowserCommon::get_records($tab,array(),array($col));
					foreach ($ret2 as $k=>$v) $arr[$k] = $v[$col];
				}
			} else {
				$ret2 = Utils_RecordBrowserCommon::get_records($this->tab,array(),array($filter));
				foreach ($ret2 as $k=>$v) /*if($v[$filter][0]!='_') */$arr[$k] = $this->get_val($filter, $v, $v['id'], true, $this->table_rows[$filter]);
			}
			if ($this->table_rows[$filter]['type']=='checkbox') $arr = array(''=>$this->lang->ht('No'), 1=>$this->lang->ht('Yes'));
			natcasesort($arr);
			$arr = array('__NULL__'=>'---')+$arr;
			$form->addElement('select', $filter_id, $this->lang->t($filter), $arr);
			$filters[] = $filter_id;
		}
		$form->addElement('submit', 'submit', 'Show');
		$def_filt = $this->get_module_variable('def_filter', array());
		$form->setDefaults($def_filt);
		$this->crits = array();
		$vals = $form->exportValues();
		foreach ($filters_all as $filter) {
			$filter_id = strtolower(str_replace(' ','_',$filter));
			if (!isset($vals[$filter_id])) $vals[$filter_id]='__NULL__';
			if (isset($this->custom_filters[$filter_id])) {
				if (isset($this->custom_filters[$filter_id]['trans'][$vals[$filter_id]]))
					foreach($this->custom_filters[$filter_id]['trans'][$vals[$filter_id]] as $k=>$v)
						$this->crits[$k] = $v;
			} elseif ($vals[$filter_id]!=='__NULL__') $this->crits[$filter_id] = $vals[$filter_id];
		}
		foreach ($vals as $k=>$v)
			if (isset($this->custom_filters[$k]) && $this->custom_filters[$k]['type']=='checkbox' && $v=='__NULL__') unset($vals[$k]);
		$this->set_module_variable('def_filter', $vals);
		$theme = $this->init_module('Base/Theme');
		$form->assign_theme('form',$theme);
		$theme->assign('filters', $filters);
		$theme->assign('show_filters', $this->lang->t('Show filters'));
		$theme->assign('hide_filters', $this->lang->t('Hide filters'));
		$theme->assign('id', $f_id);
		if (!$this->isset_module_variable('filters_defaults'))
		$this->set_module_variable('filters_defaults', $this->crits);
		elseif ($this->crits!==$this->get_module_variable('filters_defaults')) $theme->assign('dont_hide', true);
		return $this->get_html_of_module($theme, 'Filter', 'display');
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	public function navigate($func){
		$x = ModuleManager::get_instance('/Base_Box|0');
		if (!$x) trigger_error('There is no base box module instance',E_USER_ERROR);
		$args = func_get_args();
		array_shift($args);
		$x->push_main('Utils/RecordBrowser',$func,$args,array(self::$clone_result!==null?self::$clone_tab:$this->tab));
		return false;
	}
	public function back(){
		$x = ModuleManager::get_instance('/Base_Box|0');
		if(!$x) trigger_error('There is no base box module instance',E_USER_ERROR);
		return $x->pop_main();
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	public function show_data($crits = array(), $cols = array(), $order = array(), $admin = false, $special = false) {
		Utils_RecordBrowserCommon::$cols_order = $this->col_order;
		if ($this->get_access('browse')===false) {
			print($this->lang->t('You are not authorised to browse this data.'));
			return;
		}
		$this->init();
		$this->action = 'Browse';
		if (!Base_AclCommon::i_am_admin() && $admin) {
			print($this->lang->t('You don\'t have permission to access this data.'));
		}
		$gb = $this->init_module('Utils/GenericBrowser', null, $this->tab);
		$gb->set_module_variable('adv_search', $gb->get_module_variable('adv_search', $this->adv_search));
		$is_searching = $gb->get_module_variable('search','');
		if (!empty($is_searching)) {
			$this->set_module_variable('browse_mode','all');
			$gb->set_module_variable('quickjump_to',null);
		}
		if ($this->is_on_main_page) {
			$this->browse_mode = $this->get_module_variable('browse_mode', Base_User_SettingsCommon::get('Utils/RecordBrowser',$this->tab.'_default_view'));
			if (($this->browse_mode=='recent' && $this->recent==0) || ($this->browse_mode=='favorites' && !$this->favorites)) $this->set_module_variable('browse_mode', $this->browse_mode='all');
			if ($this->browse_mode!=='recent' && $this->recent>0) Base_ActionBarCommon::add('history','Recent', $this->create_callback_href(array($this,'switch_view'),array('recent')));
			if ($this->browse_mode!=='all') Base_ActionBarCommon::add('all','All', $this->create_callback_href(array($this,'switch_view'),array('all')));
			if ($this->browse_mode!=='favorites' && $this->favorites) Base_ActionBarCommon::add('favorites','Favorites', $this->create_callback_href(array($this,'switch_view'),array('favorites')));
		}

		if ($special)
			$table_columns = array(array('name'=>$this->lang->t('Select'), 'width'=>1));
		else {
			$table_columns = array();
			if (!$admin && $this->favorites)
				$table_columns[] = array('name'=>$this->lang->t('Fav'), 'width'=>1, 'order'=>':Fav');
			if (!$admin && $this->watchdog)
				$table_columns[] = array('name'=>$this->lang->t('Sub'), 'width'=>1);
		}
		$table_columns_SQL = array();
		$quickjump = DB::GetOne('SELECT quickjump FROM recordbrowser_table_properties WHERE tab=%s', array($this->tab));

		$hash = array();
		$access = $this->get_access('fields', 'browse');
		$query_cols = array();
		foreach($this->table_rows as $field => $args) {
			$hash[$args['id']] = $field;
			if ($field === 'id') continue;
			if ((!$args['visible'] && (!isset($cols[$args['id']]) || $cols[$args['id']] === false)) || $access[$args['id']]=='hide') continue;
			if (isset($cols[$args['id']]) && $cols[$args['id']] === false) continue;
			$query_cols[] = $args['id'];
			$arr = array('name'=>$this->lang->t($args['name']));
			if ($this->browse_mode!='recent' && $args['type']!=='multiselect') $arr['order'] = $field;
			if ($quickjump!=='' && $args['name']===$quickjump) $arr['quickjump'] = '"'.$args['name'];
			if ($args['type']=='text' || $args['type']=='currency' || $args['type']=='calculated') $arr['search'] = $args['id'];//str_replace(' ','_',$field);
			if ($args['type']=='checkbox' || $args['type']=='date' || $args['type']=='timestamp' || $args['type']=='commondata') {
				$arr['wrapmode'] = 'nowrap';
				$arr['width'] = 1;
			}
			if (isset($this->more_table_properties[$args['id']])) {
				foreach (array('name','wrapmode','width') as $v)
					if (isset($this->more_table_properties[$args['id']][$v])) $arr[$v] = $this->more_table_properties[$args['id']][$v];
			}
			$str = explode(';', $args['param']);
			$ref = explode('::', $str[0]);
			if ($ref[0]!='' && isset($ref[1])) $arr['search'] = '__Ref__'.$args['id'];//str_replace(' ','_',$field);
			if ($args['type']=='commondata' || $ref[0]=='__COMMON__') {
				if (!isset($ref[1]) || $ref[0]=='__COMMON__') $arr['search'] = '__RefCD__'.$args['id'];//str_replace(' ','_',$field);
				else unset($arr['search']);
			}
			$table_columns[] = $arr;
			array_push($table_columns_SQL, 'e.'.$field);
		}
		$clean_order = array();
		foreach ($order as $k => $v) {
			if (isset($this->more_table_properties[$k]) && isset($this->more_table_properties[$k]['name'])) $key = $this->more_table_properties[$k]['name'];
			elseif (isset($hash[$k])) $key = $hash[$k];
			else $key = $k;
			$clean_order[$this->lang->t($key)] = $v;
		}
		$table_columns_SQL = join(', ', $table_columns_SQL);
		if ($this->browse_mode == 'recent')
			$table_columns[] = array('name'=>$this->lang->t('Visited on'), 'wrapmode'=>'nowrap', 'width'=>1);


		$gb->set_table_columns( $table_columns );

		if ($this->browse_mode != 'recent')
			$gb->set_default_order($clean_order, $this->changed_view);

		if (!$special) {
			if ($this->add_button!==null) $label = $this->add_button;
			else $label = $this->create_callback_href(array($this, 'navigate'), array('view_entry', 'add', null, $this->custom_defaults));
			$gb->set_custom_label('<a '.$label.'><img border="0" src="'.Base_ThemeCommon::get_template_file('Base/ActionBar','icons/add-small.png').'" /></a>');
		}
		$search = $gb->get_search_query(true);
		$search_res = array();
		foreach ($search as $k=>$v) {
			$k = str_replace('__',':',$k);
			$type = explode(':',$k);
			if ($k[0]=='"') {
				$search_res['_'.$k] = $v;
				continue;
			}
			if (isset($type[1]) && $type[1]=='RefCD') {
				$search_res['"'.$k] = $v;
				continue;
			}
			if (!is_array($v)) $v = array($v);
			$r = array();
			foreach ($v as $w)
				$r[] = DB::Concat(DB::qstr('%'),DB::qstr($w),DB::qstr('%'));
			$search_res['"'.$k] = $r;
		}

		$order = $gb->get_order();
		$crits = array_merge($crits, $search_res);
		if ($this->browse_mode == 'favorites')
			$crits[':Fav'] = true;
		if ($this->browse_mode == 'recent') {
			$crits[':Recent'] = true;
			$order = array(':Visited_on'=>'DESC');
		}

		if ($admin) {
			$order = array(':Edited_on'=>'DESC');
			$form = $this->init_module('Libs/QuickForm', null, $this->tab.'_admin_filter');
			$form->addElement('select', 'show_records', 'Show records', array(0=>'all',1=>'active',2=>'deactivated'));
			$form->addElement('submit', 'submit', 'Show');
			$f = $this->get_module_variable('admin_filter', 0);
			$form->setDefaults(array('show_records'=>$f));
			self::$admin_filter = $form->exportValue('show_records');
			$this->set_module_variable('admin_filter', self::$admin_filter);
			if (self::$admin_filter==0) self::$admin_filter = '';
			if (self::$admin_filter==1) self::$admin_filter = ' AND active=1';
			if (self::$admin_filter==2) self::$admin_filter = ' AND active=0';
			$form->display();
		}

		$limit = $gb->get_limit(Utils_RecordBrowserCommon::get_records_limit($this->tab, $crits, $admin));
		$records = Utils_RecordBrowserCommon::get_records($this->tab, $crits, array()/*$query_cols - cannot apply since get_access may need whole data*/, $order, $limit, $admin);

		if ($admin) $this->browse_mode = 'all';
		if ($this->browse_mode == 'recent') {
			$rec_tmp = array();
			$ret = DB::Execute('SELECT * FROM '.$this->tab.'_recent WHERE user_id=%d ORDER BY visited_on DESC', array(Acl::get_user()));
			while ($row = $ret->FetchRow()) {
				if (!isset($records[$row[$this->tab.'_id']])) continue;
				$rec_tmp[$row[$this->tab.'_id']] = $records[$row[$this->tab.'_id']];
				$rec_tmp[$row[$this->tab.'_id']]['visited_on'] = Base_RegionalSettingsCommon::time2reg(strtotime($row['visited_on']));
			}
			$records = $rec_tmp;
		}
		if ($special) $rpicker_ind = array();

		if (!$admin && $this->favorites) {
			$favs = array();
			$ret = DB::Execute('SELECT '.$this->tab.'_id FROM '.$this->tab.'_favorite WHERE user_id=%d', array(Acl::get_user()));
			while ($row=$ret->FetchRow()) $favs[$row[$this->tab.'_id']] = true;
			$star_on = Base_ThemeCommon::get_template_file('Utils_RecordBrowser','star_fav.png');
			$star_off = Base_ThemeCommon::get_template_file('Utils_RecordBrowser','star_nofav.png');
		}
		foreach ($records as $row) {
			$gb_row = $gb->get_new_row();
			if (!$admin && $this->favorites) {
				$isfav = isset($favs[$row['id']]);
				$row_data= array('<a '.Utils_TooltipCommon::open_tag_attrs(($isfav?$this->lang->t('This item is on your favourites list<br>Click to remove it from your favorites'):$this->lang->t('Click to add this item to favorites'))).' '.$this->create_callback_href(array($this,($isfav?'remove_from_favs':'add_to_favs')), array($row['id'])).'><img style="width: 14px; height: 14px; vertical-align: middle;" border="0" src="'.($isfav==false?$star_off:$star_on).'" /></a>');
			} else $row_data= array();
			if (!$admin && $this->watchdog) {
				$row_data[] = Utils_WatchdogCommon::get_change_subscription_icon($this->tab,$row['id']);
			}
			if ($special) {
				$func = $this->get_module_variable('format_func');
				$element = $this->get_module_variable('element');
				$row_data= array('<a href="javascript:rpicker_addto(\''.$element.'\','.$row['id'].',\''.Base_ThemeCommon::get_template_file('images/active_on.png').'\',\''.Base_ThemeCommon::get_template_file('images/active_off2.png').'\',\''.(is_callable($func)?strip_tags(call_user_func($func, $row, true)):'').'\');"><img border="0" name="leightbox_rpicker_'.$element.'_'.$row['id'].'" /></a>');
				$rpicker_ind[] = $row['id'];
			}
			foreach($query_cols as $argsid) {
				if ($access[$argsid]=='hide') continue;
				$field = $hash[$argsid];
				$args = $this->table_rows[$field]; 
				$value = $this->get_val($field, $row, $row['id'], $special, $args);
				if (isset($this->cut[$args['id']])) {
					$value = $this->cut_string($value,$this->cut[$args['id']]);
				}
				if ($args['type']=='currency') $value = array('style'=>'text-align:right;','value'=>$value);
				$row_data[] = $value;
			}
			if ($this->browse_mode == 'recent')
				$row_data[] = $row['visited_on'];

			$gb_row->add_data_array($row_data);
			if (!isset($cols['Actions']) || $cols['Actions'])
			{
				if (!$special) {
					$gb_row->add_action($this->create_callback_href(array($this,'navigate'),array('view_entry', 'view', $row['id'])),'View');
					if ($this->get_access('edit',$row)) $gb_row->add_action($this->create_callback_href(array($this,'navigate'),array('view_entry', 'edit',$row['id'])),'Edit');
					if ($admin) {
						if (!$row['active']) $gb_row->add_action($this->create_callback_href(array($this,'set_active'),array($row['id'],true)),'Activate', null, 'active-off');
						else $gb_row->add_action($this->create_callback_href(array($this,'set_active'),array($row['id'],false)),'Deactivate', null, 'active-on');
						$info = Utils_RecordBrowserCommon::get_record_info($this->tab, $row['id']);
						if ($info['edited_by']===null) $gb_row->add_action('','This record was never edited',null,'history_inactive');
						else $gb_row->add_action($this->create_callback_href(array($this,'navigate'),array('view_edit_history', $row['id'])),'View edit history',null,'history');
					} else
					if ($this->get_access('delete',$row)) $gb_row->add_action($this->create_confirm_callback_href($this->lang->t('Are you sure you want to delete this record?'),array('Utils_RecordBrowserCommon','delete_record'),array($this->tab, $row['id'])),'Delete');
				}
				$gb_row->add_info(Utils_RecordBrowserCommon::get_html_record_info($this->tab, isset($info)?$info:$row['id']));
			}
		}
		if (!$special && $this->add_in_table && $this->get_access('add')) {
			$form = $this->init_module('Libs/QuickForm',null, 'add_in_table__'.$this->tab);
			$form->setDefaults($this->custom_defaults);

			$visible_cols = array();
			foreach($this->table_rows as $field => $args)
				if (($args['visible'] && !isset($cols[$args['id']])) || (isset($cols[$args['id']]) && $cols[$args['id']] === true))
					$visible_cols[$args['id']] = true;
			$this->prepare_view_entry_details(null, 'add', null, $form, $visible_cols);

			if ($form->validate()) {
				$values = $form->exportValues();
				$dpm = DB::GetOne('SELECT data_process_method FROM recordbrowser_table_properties WHERE tab=%s', array($this->tab));
				$method = '';
				if ($dpm!=='') {
					$method = explode('::',$dpm);
					if (is_callable($method)) $values = call_user_func($method, $values, 'add');
					else $dpm = '';
				}
				$id = Utils_RecordBrowserCommon::new_record($this->tab, $values);
				$values['id'] = $id;
				Utils_WatchdogCommon::new_event($this->tab,$values['id'],'C');
				if ($dpm!=='')
					call_user_func($method, $values, 'added');
				location(array());
			}

			$renderer = new HTML_QuickForm_Renderer_TCMSArraySmarty();
			$form->accept($renderer);
			$data = $renderer->toArray();

			$gb->set_prefix($data['javascript'].'<form '.$data['attributes'].'>'.$data['hidden']."\n");
			$gb->set_postfix("</form>\n");

			if (!$admin && $this->favorites) {
				$row_data= array('&nbsp;');
			} else $row_data= array();

			foreach($visible_cols as $k => $v)
				$row_data[] = $data[$k]['error'].$data[$k]['html'];

			if ($this->browse_mode == 'recent')
				$row_data[] = '&nbsp;';

			$gb_row = $gb->get_new_row();
			$gb_row->add_action($form->get_submit_form_href(),'Submit');
			$gb_row->add_data_array($row_data);
		}
		if ($special) {
			$this->set_module_variable('rpicker_ind',$rpicker_ind);
			return $this->get_html_of_module($gb);
		} else $this->display_module($gb);
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	public function delete_record($id) {
		Utils_RecordBrowserCommon::delete_record($this->tab, $id);
		return $this->back();
	}
	public function clone_record($id) {
		if (self::$clone_result!==null) {
			if (is_numeric(self::$clone_result)) $this->navigate('view_entry', 'view', self::$clone_result);
			self::$clone_result = null;
			return false;
		}
		$record = Utils_RecordBrowserCommon::get_record($this->tab, $id);
		$access = $this->get_access('fields',$record);
		if (is_array($access))
			foreach ($access as $k=>$v)
				if ($v=='hide') unset($record[$k]);
		$this->navigate('view_entry', 'add', null, $record);
		return true;
	}
	public function view_entry($mode='view', $id = null, $defaults = array()) {
		Utils_RecordBrowserCommon::$cols_order = array();
		$js = ($mode!='view');
		$time = microtime(true);
		if ($this->is_back()) {
			self::$clone_result = 'canceled';
			return $this->back();
		}

		if ($id!==null) Utils_WatchdogCommon::notified($this->tab,$id);

		$this->init();
		$this->record = Utils_RecordBrowserCommon::get_record($this->tab, $id);
		if ($mode!='add' && !$this->record['active'] && !Base_AclCommon::i_am_admin()) return $this->back();

		if ($mode=='view')
			$this->record = Utils_RecordBrowserCommon::format_long_text($this->tab,$this->record);

		$tb = $this->init_module('Utils/TabbedBrowser');
		self::$tab_param = $tb->get_path();

		$theme = $this->init_module('Base/Theme');
		if ($mode=='view') {
			$dpm = DB::GetOne('SELECT data_process_method FROM recordbrowser_table_properties WHERE tab=%s', array($this->tab));
			if ($dpm!=='') {
				$method = explode('::',$dpm);
				if (is_callable($method)) {
					$theme_stuff = call_user_func($method, $this->record, 'view');
					if (is_array($theme_stuff))
						foreach ($theme_stuff as $k=>$v)
							$theme->assign($k, $v);
				}
			}
		}
		switch ($mode) {
			case 'add':		$this->action = 'New record'; break;
			case 'edit':	$this->action = 'Edit record'; break;
			case 'view':	$this->action = 'View record'; break;
		}
		$this->fields_permission = $this->get_access('fields', isset($this->record)?$this->record:'new');

		if($mode!='add')
			Utils_RecordBrowserCommon::add_recent_entry($this->tab, Acl::get_user(),$id);

		$form = $this->init_module('Libs/QuickForm',null, $mode);
		if($mode=='add') {
			$form->setDefaults($defaults);
			foreach ($defaults as $k=>$v)
				$this->custom_defaults[$k] = $v;
		}

		$this->prepare_view_entry_details($this->record, $mode, $id, $form);

		if ($form->validate()) {
			$values = $form->exportValues();
			$values['id'] = $id;
			$dpm = DB::GetOne('SELECT data_process_method FROM recordbrowser_table_properties WHERE tab=%s', array($this->tab));
			$method = '';
			if ($dpm!=='') {
				$method = explode('::',$dpm);
				if (is_callable($method)) $values = call_user_func($method, $values, $mode);
			}
			if ($mode=='add') {
				$id = Utils_RecordBrowserCommon::new_record($this->tab, $values);
				self::$clone_result = $id;
				self::$clone_tab = $this->tab;
				$values['id'] = $id;
				Utils_WatchdogCommon::new_event($this->tab,$values['id'],'C');
				if ($dpm!=='')
					call_user_func($method, $values, 'added');
				return $this->back();
			}
			$time_from = date('Y-m-d H:i:s', $this->get_module_variable('edit_start_time'));
			$ret = DB::Execute('SELECT * FROM '.$this->tab.'_edit_history WHERE edited_on>=%T AND '.$this->tab.'_id=%d',array($time_from, $id));
			if ($ret->EOF) {
				$this->update_record($id,$values);
				return $this->back();
			}
			$this->dirty_read_changes($id, $time_from);
		}
		if ($mode=='edit') $this->set_module_variable('edit_start_time',$time);

		if ($mode=='view') {
			if ($this->get_access('edit',$this->record)) Base_ActionBarCommon::add('edit', 'Edit', $this->create_callback_href(array($this,'navigate'), array('view_entry','edit',$id)));
			if ($this->get_access('delete',$this->record)) Base_ActionBarCommon::add('delete', 'Delete', $this->create_confirm_callback_href($this->lang->t('Are you sure you want to delete this record?'),array($this,'delete_record'),array($id)));
			Base_ActionBarCommon::add('clone','Clone', $this->create_confirm_callback_href($this->lang->ht('You are about to create a copy of this record. Do you want to continue?'),array($this,'clone_record'),array($id)));
			Base_ActionBarCommon::add('back', 'Back', $this->create_back_href());
		} else {
			Base_ActionBarCommon::add('save', 'Save', $form->get_submit_form_href());
			Base_ActionBarCommon::add('delete', 'Cancel', $this->create_back_href());
		}

		if ($mode!='add') {
			$isfav_query_result = DB::GetOne('SELECT user_id FROM '.$this->tab.'_favorite WHERE user_id=%d AND '.$this->tab.'_id=%d', array(Acl::get_user(), $id));
			$isfav = ($isfav_query_result!==false && $isfav_query_result!==null);
			$theme -> assign('info_tooltip', '<a '.Utils_TooltipCommon::open_tag_attrs(Utils_RecordBrowserCommon::get_html_record_info($this->tab, $id)).'><img border="0" src="'.Base_ThemeCommon::get_template_file('Utils_RecordBrowser','info.png').'" /></a>');
			$row_data= array();
			$fav = DB::GetOne('SELECT user_id FROM '.$this->tab.'_favorite WHERE user_id=%d AND '.$this->tab.'_id=%s', array(Acl::get_user(), $id));

			if ($this->favorites)
				$theme -> assign('fav_tooltip', '<a '.Utils_TooltipCommon::open_tag_attrs(($isfav?$this->lang->t('This item is on your favourites list<br>Click to remove it from your favorites'):$this->lang->t('Click to add this item to favorites'))).' '.$this->create_callback_href(array($this,($isfav?'remove_from_favs':'add_to_favs')), array($id)).'><img style="width: 14px; height: 14px;" border="0" src="'.Base_ThemeCommon::get_template_file('Utils_RecordBrowser','star_'.($isfav==false?'no':'').'fav.png').'" /></a>');
			if ($this->watchdog)
				$theme -> assign('subscription_tooltip', Utils_WatchdogCommon::get_change_subscription_icon($this->tab, $id));
			if ($this->full_history) {
				$info = Utils_RecordBrowserCommon::get_record_info($this->tab, $id);
				if ($info['edited_by']===null) $theme -> assign('history_tooltip', '<a '.Utils_TooltipCommon::open_tag_attrs($this->lang->t('This record was never edited')).'><img border="0" src="'.Base_ThemeCommon::get_template_file('Utils_RecordBrowser','history_inactive.png').'" /></a>');
				else $theme -> assign('history_tooltip', '<a '.Utils_TooltipCommon::open_tag_attrs($this->lang->t('Click to view edit history of currently displayed record')).' '.$this->create_callback_href(array($this,'navigate'), array('view_edit_history', $id)).'><img border="0" src="'.Base_ThemeCommon::get_template_file('Utils_RecordBrowser','history.png').'" /></a>');
			}
		}
		if ($mode=='edit')
			foreach($this->table_rows as $field => $args)
				if ($this->fields_permission[$args['id']]==='read-only')
					$form->freeze($args['id']);

		if ($mode=='view') $form->freeze();
		$renderer = new HTML_QuickForm_Renderer_TCMSArraySmarty();
		$form->accept($renderer);
		$data = $renderer->toArray();

		print($data['javascript'].'<form '.$data['attributes'].'>'.$data['hidden']."\n");

		$last_page = DB::GetOne('SELECT MIN(position) FROM '.$this->tab.'_field WHERE type = \'page_split\' AND field != \'General\'');
		$label = DB::GetRow('SELECT field, param FROM '.$this->tab.'_field WHERE position=%s', array($last_page));
		$cols = $label['param'];
		$label = $label['field'];
		$this->mode = $mode;
		$this->view_entry_details(1, $last_page, $data, $theme, true);
		$ret = DB::Execute('SELECT position, field, param FROM '.$this->tab.'_field WHERE type = \'page_split\' AND position > %d', array($last_page));
		$row = true;
		if ($mode=='view')
			print("</form>\n");
		while ($row) {
			$row = $ret->FetchRow();
			if ($row) $pos = $row['position'];
			else $pos = DB::GetOne('SELECT MAX(position) FROM '.$this->tab.'_field')+1;
			if ($pos - $last_page>1) $tb->set_tab($this->lang->t($label),array($this,'view_entry_details'), array($last_page, $pos+1, $data, null, false, $cols), $js);
			$cols = $row['param'];
			$last_page = $pos;
			if ($row) $label = $row['field'];
		}
		if ($mode!='add' && $mode!='edit') {
			$ret = DB::Execute('SELECT * FROM recordbrowser_addon WHERE tab=%s', array($this->tab));
			while ($row = $ret->FetchRow()) {
				$mod = $this->init_module($row['module']);
				if (!is_callable(array($mod,$row['func']))) $tb->set_tab($this->lang->t($row['label']),array($this, 'broken_addon'), $js);
				else $tb->set_tab($this->lang->t($row['label']),array($this, 'display_module'), array($mod, array($this->record), $row['func']), $js);
			}
		}
		$this->display_module($tb);
		if ($mode=='add' || $mode=='edit') print("</form>\n");
		$tb->tag();

		return true;
	} //view_entry

	public function broken_addon(){
		print('Addon is broken, please contact system administrator.');
	}

	public function view_entry_details($from, $to, $data, $theme=null, $main_page = false, $cols = 2){
		if ($theme==null) $theme = $this->init_module('Base/Theme');
		$fields = array();
		$longfields = array();
		foreach($this->table_rows as $field => $args) {
			if (!isset($data[$args['id']]) || $data[$args['id']]['type']=='hidden')	continue;
			if ($args['position'] >= $from && ($to == -1 || $args['position'] < $to))
			{
				if (!isset($data[$args['id']])) $data[$args['id']] = array('label'=>'', 'html'=>'');
					$arr = array(	'label'=>$data[$args['id']]['label'],
									'element'=>$args['id'],
									'advanced'=>isset($this->advanced[$args['id']])?$this->advanced[$args['id']]:'',
									'html'=>$data[$args['id']]['html'],
									'style'=>$args['style'],
									'error'=>isset($data[$args['id']]['error'])?$data[$args['id']]['error']:null,
									'required'=>isset($args['required'])?$args['required']:null,
									'type'=>$args['type']);
					if ($args['type']<>'long text') $fields[$args['id']] = $arr; else $longfields[$args['id']] = $arr;
			}
		}
		if ($cols==0) $cols=2;
		$theme->assign('fields', $fields);
		$theme->assign('cols', $cols);
		$theme->assign('longfields', $longfields);
		$theme->assign('action', $this->mode);
		$theme->assign('form_data', $data);
		$theme->assign('required_note', $this->lang->t('Indicates required fields.'));

		$theme->assign('caption',$this->lang->t($this->caption));
		$theme->assign('icon',$this->icon);

		$theme->assign('main_page',$main_page);

		if ($main_page) {
			$tpl = DB::GetOne('SELECT tpl FROM recordbrowser_table_properties WHERE tab=%s', array($this->tab));
			$theme->assign('raw_data',$this->record);
		} else {
			$tpl = '';
			if ($this->mode=='view') print('<form>');
		}
		$theme->display(($tpl!=='')?$tpl:'View_entry', ($tpl!==''));
		if (!$main_page && $this->mode=='view') print('</form>');
	}

	public function timestamp_required($v) {
		return strtotime($v['datepicker'])!==false;
	}

	public function prepare_view_entry_details($record, $mode, $id, $form, $visible_cols = null){
		$init_js = '';
		foreach($this->table_rows as $field => $args){
			if ($this->fields_permission[$args['id']]==='hide') continue;
			if ($visible_cols!==null && !isset($visible_cols[$args['id']])) continue;
			if ($args['type']=='hidden') {
				$form->addElement('hidden', $args['id']);
				$form->setDefaults(array($args['id']=>$record[$args['id']]));
				continue;
			}
			if (isset($this->QFfield_callback_table[$field])) {
				call_user_func($this->QFfield_callback_table[$field], $form, $args['id'], $this->lang->t($args['name']), $mode, $mode=='add'?(isset($this->custom_defaults[$args['id']])?$this->custom_defaults[$args['id']]:''):$record[$args['id']], $args, $this, $this->display_callback_table);
			} else {
				if ($mode!=='add' && $mode!=='edit') {
					if ($args['type']!='checkbox' && $args['type']!='commondata') {
						$def = $this->get_val($field, $record, $id, false, $args);
						$form->addElement('static', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
						$form->setDefaults(array($args['id']=>$def));
						continue;
					}
				}
				if (isset($this->requires[$field]))
					if ($mode=='add' || $mode=='edit') {
						foreach($this->requires[$field] as $k=>$v) {
							if (!is_array($v)) $v = array($v);
							$r_id = strtolower(str_replace(' ','_',$k));
							$js = 	'Event.observe(\''.$r_id.'\',\'change\', onchange_'.$args['id'].'__'.$k.');'.
									'function onchange_'.$args['id'].'__'.$k.'() {'.
									'if (0';
							foreach ($v as $w)
								$js .= ' || document.forms[\''.$form->getAttribute('name').'\'].'.$r_id.'.value == \''.$w.'\'';
							$js .= 	') { '.
									'document.forms[\''.$form->getAttribute('name').'\'].'.$args['id'].'.style.display = \'inline\';'.
									'document.getElementById(\'_'.$args['id'].'__label\').style.display = \'inline\';'.
									'} else { '.
									'document.forms[\''.$form->getAttribute('name').'\'].'.$args['id'].'.style.display = \'none\';'.
									'document.getElementById(\'_'.$args['id'].'__label\').style.display = \'none\';'.
									'}};';
							$init_js .= 'onchange_'.$args['id'].'__'.$k.'();';
							eval_js($js);
						}
					} else {
						$hidden = false;
						foreach($this->requires[$field] as $k=>$v) {
							if (!is_array($v)) $v = array($v);
							$r_id = strtolower(str_replace(' ','_',$k));
							foreach ($v as $w) {
								if ($record[$k] != $w) {
									$hidden = true;
									break;
								}
							}
							if ($hidden) break;
						}
						if ($hidden) continue;
					}
				switch ($args['type']) {
					case 'calculated':	$form->addElement('static', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
										if ($record[$args['id']]!='' && $mode=='edit')
											$form->setDefaults(array($args['id']=>$this->get_val($field, $record, $record['id'], false, $args)));
										else
											$form->setDefaults(array($args['id']=>'['.$this->lang->t('formula').']'));
										break;
					case 'integer':		$form->addElement('text', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
										$form->addRule($args['id'], $this->lang->t('Only numbers are allowed.'), 'numeric');
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'checkbox':	$form->addElement('checkbox', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', '', array('id'=>$args['id']));
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'currency':	$form->addElement('currency', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'text':		if ($mode!=='view') $form->addElement('text', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id'], 'maxlength'=>$args['param']));
										else $form->addElement('static', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
										$form->addRule($args['id'], $this->lang->t('Maximum length for this field is '.$args['param'].'.'), 'maxlength', $args['param']);
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'long text':	$form->addElement('textarea', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id'], 'onkeypress'=>'var key=event.which || event.keyCode;return this.value.length < 255 || ((key<32 || key>126) && key!=10 && key!=13) ;'));
										$form->addRule($args['id'], $this->lang->t('Maximum length for this field is 255.'), 'maxlength', 255);
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'date':		$form->addElement('datepicker', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'timestamp':	$form->addElement('timestamp', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
										static $rule_defined = false;
										if (!$rule_defined) $form->registerRule('timestamp_required', 'callback', 'timestamp_required', $this);
										$rule_defined = true;
										if (isset($args['required']) && $args['required']) $form->addRule($args['id'], Base_LangCommon::ts('Utils_RecordBrowser','Field required'), 'timestamp_required');
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'commondata':	$param = explode('::',$args['param']);
										foreach ($param as $k=>$v) if ($k!=0) $param[$k] = strtolower(str_replace(' ','_',$v));
										$form->addElement($args['type'], $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', $param, array('empty_option'=>$args['required'], 'id'=>$args['id']));
										if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										break;
					case 'select':
					case 'multiselect':	$comp = array();
										if ($args['type']==='select') $comp[''] = '---';
										$ref = explode(';',$args['param']);
										if (isset($ref[1])) $crits_callback = $ref[1];
										if (isset($ref[2])) $multi_adv_params = call_user_func(explode('::',$ref[2]));
										if (!isset($multi_adv_params) || !is_array($multi_adv_params)) $multi_adv_params = array();
										if (!isset($multi_adv_params['order'])) $multi_adv_params['order'] = array();
										if (!isset($multi_adv_params['cols'])) $multi_adv_params['cols'] = array();
										if (!isset($multi_adv_params['format_callback'])) $multi_adv_params['format_callback'] = array();
										$ref = $ref[0];
										list($tab, $col) = explode('::',$ref);
										if ($tab=='__COMMON__') {
											$data = Utils_CommonDataCommon::get_translated_array($col, true);
											if (!is_array($data)) $data = array();
										}
										if ($mode=='add' || $mode=='edit') {
											if ($tab=='__COMMON__')
												$comp = $comp+$data;
											else {
												if (isset($crits_callback)) {
													$crit_callback = explode('::',$crits_callback);
													$crits = call_user_func($crit_callback, false, $record);
													$adv_crits = call_user_func($crit_callback, true, $record);
													if ($adv_crits === $crits) $adv_crits = null;
													if ($adv_crits !== null) {
														$rp = $this->init_module('Utils/RecordBrowser/RecordPicker');
														$this->display_module($rp, array($tab, $args['id'], $multi_adv_params['format_callback'], $adv_crits, $multi_adv_params['cols'], $multi_adv_params['order']));
														$this->advanced[$args['id']] = $rp->create_open_link($this->lang->t('Advanced'));
													}
												} else $crits = array();
												$records = Utils_RecordBrowserCommon::get_records($tab, $crits, empty($multi_adv_params['format_callback'])?array($col):array());
												$col_id = strtolower(str_replace(' ','_',$col));
												$ext_rec = array();
												if (isset($record[$args['id']])) {
													if (!is_array($record[$args['id']])) {
														if ($record[$args['id']]!='') $record[$args['id']] = array($record[$args['id']]); else $record[$args['id']] = array();
													}
													$ext_rec = array_flip($record[$args['id']]);
													foreach($ext_rec as $k=>$v) {
														$c = Utils_RecordBrowserCommon::get_record($tab, $k);
														if (!empty($multi_adv_params['format_callback'])) $n = call_user_func($multi_adv_params['format_callback'], $c);
														else $n = $v[$col_id];
														$comp[$k] = $n;
													}
												}
												foreach ($records as $k=>$v) {
													if (!empty($multi_adv_params['format_callback'])) $n = call_user_func($multi_adv_params['format_callback'], $v);
													else $n = $v[$col_id];
													$comp[$k] = $n;
													unset($ext_rec[$v['id']]);
												}
												natcasesort($comp);
											}
											$form->addElement($args['type'], $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', $comp, array('id'=>$args['id']));
											if ($mode!=='add') $form->setDefaults(array($args['id']=>$record[$args['id']]));
										} else {
											$form->addElement('static', $args['id'], '<span id="_'.$args['id'].'__label">'.$this->lang->t($args['name']).'</span>', array('id'=>$args['id']));
											$form->setDefaults(array($args['id']=>$record[$args['id']]));
										}
										break;
				}
			}
			if ($args['required'])
				$form->addRule($args['id'], $this->lang->t('Field required'), 'required');
		}
		eval_js($init_js);
	}
	public function add_to_favs($id) {
		DB::Execute('INSERT INTO '.$this->tab.'_favorite (user_id, '.$this->tab.'_id) VALUES (%d, %d)', array(Acl::get_user(), $id));
	}
	public function remove_from_favs($id) {
		DB::Execute('DELETE FROM '.$this->tab.'_favorite WHERE user_id=%d AND '.$this->tab.'_id=%d', array(Acl::get_user(), $id));
	}
	public function update_record($id,$values) {
		Utils_RecordBrowserCommon::update_record($this->tab, $id, $values, true);
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	public function administrator_panel() {
		$this->init();
		$tb = $this->init_module('Utils/TabbedBrowser');

		$tb->set_tab($this->lang->t('Manage Records'),array($this, 'show_data'), array(array(), array(), array(), true) );
		$tb->set_tab($this->lang->t('Manage Fields'),array($this, 'setup_loader') );

		$tb->body();
		$tb->tag();
	}

	public function new_page() {
		DB::StartTrans();
		$max_f = DB::GetOne('SELECT MAX(position) FROM '.$this->tab.'_field');
		$num = 1;
		do {
			$num++;
			$x = DB::GetOne('SELECT position FROM '.$this->tab.'_field WHERE type = \'page_split\' AND field = %s', array('Details '.$num));
		} while ($x!==false && $x!==null);
		DB::Execute('INSERT INTO '.$this->tab.'_field (field, type, extra, position) VALUES(%s, \'page_split\', 1, %d)', array('Details '.$num, $max_f+1));
		DB::CompleteTrans();
	}
	public function delete_page($id) {
		DB::StartTrans();
		$p = DB::GetOne('SELECT position FROM '.$this->tab.'_field WHERE field=%s', array($id));
		DB::Execute('UPDATE '.$this->tab.'_field SET position = position-1 WHERE position > %d', array($p));
		DB::Execute('DELETE FROM '.$this->tab.'_field WHERE field=%s', array($id));
		DB::CompleteTrans();
	}
	public function edit_page($id) {
		if ($this->is_back())
			return false;
		$this->init();
		$form = $this->init_module('Libs/QuickForm', null, 'edit_page');

		$form->addElement('header', null, $this->lang->t('Edit page properties'));
		$form->addElement('text', 'label', $this->lang->t('Label'));
		$form->registerRule('check_if_column_exists', 'callback', 'check_if_column_exists', $this);
		$form->registerRule('check_if_no_id', 'callback', 'check_if_no_id', $this);
		$form->addRule('label', $this->lang->t('Field required.'), 'required');
		$form->addRule('label', $this->lang->t('Field or Page with this name already exists.'), 'check_if_column_exists');
		$form->addRule('label', $this->lang->t('Only letters and space are allowed.'), 'regex', '/^[a-zA-Z ]*$/');
		$form->addRule('label', $this->lang->t('"ID" as page name is not allowed.'), 'check_if_no_id');
		$form->setDefaults(array('label'=>$id));

		$ok_b = HTML_QuickForm::createElement('submit', 'submit_button', $this->lang->ht('OK'));
		$cancel_b = HTML_QuickForm::createElement('button', 'cancel_button', $this->lang->ht('Cancel'), $this->create_back_href());
		$form->addGroup(array($ok_b, $cancel_b));

		if($form->validate()) {
			$data = $form->exportValues();
			foreach($data as $key=>$val)
				$data[$key] = htmlspecialchars($val);
			DB::Execute('UPDATE '.$this->tab.'_field SET field=%s WHERE field=%s',
						array($data['label'], $id));
			return false;
		}
		$form->display();
		return true;
	}
	public function setup_loader() {
		$this->init(true);
		$action = $this->get_module_variable_or_unique_href_variable('setup_action', 'show');
		$subject = $this->get_module_variable_or_unique_href_variable('subject', 'regular');

		Base_ActionBarCommon::add('add','New field',$this->create_callback_href(array($this, 'view_field')));
		Base_ActionBarCommon::add('add','New page',$this->create_callback_href(array($this, 'new_page')));
		$gb = $this->init_module('Utils/GenericBrowser', null, 'fields');
		$gb->set_table_columns(array(
			array('name'=>$this->lang->t('Field'), 'width'=>20),
			array('name'=>$this->lang->t('Type'), 'width'=>20),
			array('name'=>$this->lang->t('Table view'), 'width'=>5),
			array('name'=>$this->lang->t('Required'), 'width'=>5),
			array('name'=>$this->lang->t('Filter'), 'width'=>5),
			array('name'=>$this->lang->t('Parameters'), 'width'=>5))
		);

		//read database
		$rows = count($this->table_rows);
		$max_p = DB::GetOne('SELECT position FROM '.$this->tab.'_field WHERE field = \'Details\'');
		foreach($this->table_rows as $field=>$args) {
			$gb_row = $gb->get_new_row();
			if($args['extra']) {
				if ($args['type'] != 'page_split') {
					$gb_row->add_action($this->create_callback_href(array($this, 'view_field'),array('edit',$field)),'Edit');
				} else {
					$gb_row->add_action($this->create_callback_href(array($this, 'delete_page'),array($field)),'Delete');
					$gb_row->add_action($this->create_callback_href(array($this, 'edit_page'),array($field)),'Edit');
				}
			} else {
				if ($field!='General' && $args['type']=='page_split')
					$gb_row->add_action($this->create_callback_href(array($this, 'edit_page'),array($field)),'Edit');
			}
			if ($args['type']!=='page_split' && $args['extra']){
				if ($args['active']) $gb_row->add_action($this->create_callback_href(array($this, 'set_field_active'),array($field, false)),'Deactivate', null, 'active-on');
				else $gb_row->add_action($this->create_callback_href(array($this, 'set_field_active'),array($field, true)),'Activate', null, 'active-off');
			}
			if ($args['position']>$max_p && $args['position']<=$rows || ($args['position']<$max_p-1 && $args['position']>2))
				$gb_row->add_action($this->create_callback_href(array($this, 'move_field'),array($field, $args['position'], +1)),'Move down', null, 'move-down');
			if ($args['position']>$max_p+1 || ($args['position']<$max_p && $args['position']>3))
				$gb_row->add_action($this->create_callback_href(array($this, 'move_field'),array($field, $args['position'], -1)),'Move up', null, 'move-up');
			if ($args['type']=='text')
				$args['param'] = $this->lang->t('Length').' '.$args['param'];
			if ($args['type'] == 'page_split')
					$gb_row->add_data(
						array('style'=>'background-color: #DFDFFF;', 'value'=>$field),
						array('style'=>'background-color: #DFDFFF;', 'value'=>$this->lang->t('Page Split')),
						array('style'=>'background-color: #DFDFFF;', 'value'=>''),
						array('style'=>'background-color: #DFDFFF;', 'value'=>''),
						array('style'=>'background-color: #DFDFFF;', 'value'=>''),
						array('style'=>'background-color: #DFDFFF;', 'value'=>'')
					);
				else
					$gb_row->add_data(
						$field,
						$args['type'],
						$args['visible']?$this->lang->t('<b>Yes</b>'):$this->lang->t('No'),
						$args['required']?$this->lang->t('<b>Yes</b>'):$this->lang->t('No'),
						$args['filter']?$this->lang->t('<b>Yes</b>'):$this->lang->t('No'),
						$args['param']
					);
		}
		$this->display_module($gb);
	}
	public function move_field($field, $pos, $dir){
		DB::StartTrans();
		DB::Execute('UPDATE '.$this->tab.'_field SET position=%d WHERE position=%d',array($pos, $pos+$dir));
		DB::Execute('UPDATE '.$this->tab.'_field SET position=%d WHERE field=%s',array($pos+$dir, $field));
		DB::CompleteTrans();
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	public function set_field_active($field, $set=true) {
		DB::Execute('UPDATE '.$this->tab.'_field SET active=%d WHERE field=%s',array($set?1:0,$field));
		return false;
	} //submit_delete_field
	//////////////////////////////////////////////////////////////////////////////////////////
	public function view_field($action = 'add', $id = null) {
		if ($this->is_back()) return false;

		$data_type = array(
			'currency'=>'currency',
			'checkbox'=>'checkbox',
			'date'=>'date',
			'integer'=>'integer',
			'text'=>'text',
			'long text'=>'long text'
		);
		natcasesort($data_type);

		if (!isset($this->lang)) $this->lang = $this->init_module('Base/Lang');
		$form = $this->init_module('Libs/QuickForm');

		switch ($action) {
			case 'add': $form->addElement('header', null, $this->lang->t('Add new field'));
						break;
			case 'edit': $form->addElement('header', null, $this->lang->t('Edit field properties'));
						break;
		}
		$form->addElement('text', 'field', $this->lang->t('Field'));
		$form->registerRule('check_if_column_exists', 'callback', 'check_if_column_exists', $this);
		$form->registerRule('check_if_no_id', 'callback', 'check_if_no_id', $this);
		$form->addRule('field', $this->lang->t('Field required.'), 'required');
		$form->addRule('field', $this->lang->t('Field with this name already exists.'), 'check_if_column_exists');
		$form->addRule('field', $this->lang->t('Only letters and space are allowed.'), 'regex', '/^[a-zA-Z ]*$/');
		$form->addRule('field', $this->lang->t('"ID" as field name is not allowed.'), 'check_if_no_id');


		if ($action=='edit') {
			$row = DB::GetRow('SELECT field, type, visible, required, param, filter FROM '.$this->tab.'_field WHERE field=%s',array($id));
			$form->setDefaults($row);
			$form->addElement('static', 'select_data_type', $this->lang->t('Data Type'), $row['type']);
			$selected_data= $row['type'];
		} else {
			$form->addElement('select', 'select_data_type', $this->lang->t('Data Type'), $data_type);
			$selected_data= $form->exportValue('select_data_type');
			$form->setDefaults(array('visible'=>1));
		}
		switch($selected_data) {
			case 'text':
				if ($action=='edit')
					$form->addElement('static', 'text_length', $this->lang->t('Length'), $row['param']);
				else {
					$form->addElement('text', 'text_length', $this->lang->t('Length'));
					$form->addRule('text_length', $this->lang->t('Field required'), 'required');
					$form->addRule('text_length', $this->lang->t('Must be a number greater than 0.'), 'regex', '/^[1-9][0-9]*$/');
				}
				break;
		}
		$form->addElement('checkbox', 'visible', $this->lang->t('Table view'));
		$form->addElement('checkbox', 'required', $this->lang->t('Required'));
		$form->addElement('checkbox', 'filter', $this->lang->t('Filter enabled'));

		$ok_b = HTML_QuickForm::createElement('submit', 'submit_button', $this->lang->ht('OK'));
		$cancel_b = HTML_QuickForm::createElement('button', 'cancel_button', $this->lang->ht('Cancel'), $this->create_back_href());
		$form->addGroup(array($ok_b, $cancel_b));

		if($form->validate()) {
			if ($action=='edit') {
				$data = $form->exportValues();
				if(!isset($data['visible']) || $data['visible'] == '') $data['visible'] = 0;
				if(!isset($data['required']) || $data['required'] == '') $data['required'] = 0;
				if(!isset($data['filter']) || $data['filter'] == '') $data['filter'] = 0;

				foreach($data as $key=>$val)
					$data[$key] = htmlspecialchars($val);

				DB::StartTrans();
				DB::Execute('UPDATE '.$this->tab.'_field SET field=%s, visible=%d, required=%d, filter=%d WHERE field=%s',
							array($data['field'], $data['visible'], $data['required'], $data['filter'], $id));
				DB::Execute('UPDATE '.$this->tab.'_edit_history_data SET field=%s WHERE field=%s',
							array($data['field'], $id));
				$data['field'] = strtolower(str_replace(' ','_',$data['field']));
				if (preg_match('/^[a-z0-9_]*$/',$id)===false) trigger_error('Invalid column name: '.$id);
				if (preg_match('/^[a-z0-9_]*$/',$data['field'])===false) trigger_error('Invalid column name: '.$data['field']);
				DB::Execute('ALTER TABLE '.$this->tab.'_data_1 RENAME COLUMN f_'.$id.' TO f_'.$data['field']);
				// TODO: check above query for security holes!
				DB::CompleteTrans();
				return false;
			} else {
				if ($form->process(array($this, 'submit_add_field')))
					return false;
			}
		}
		$form->display();
		return true;
	}
	public function check_if_no_id($arg){
		return !preg_match('/^[iI][dD]$/',$arg);
	}
	public function check_if_column_exists($arg){
		$this->init(true);
		foreach($this->table_rows as $field=>$args)
			if (strtolower($args['name']) == strtolower($arg))
				return false;
		return true;
	}

	public function submit_add_field($data) {
		$param = '';
		switch($data['select_data_type']) {
			case 'text':
				$param = $data['text_length'];
				break;
		}
		if(!isset($data['visible']) || $data['visible'] == '') $data['visible'] = 0;
		if(!isset($data['required']) || $data['required'] == '') $data['required'] = 0;
		if(!isset($data['filter']) || $data['filter'] == '') $data['filter'] = 0;

		foreach($data as $key=>$val)
			$data[$key] = htmlspecialchars($val);

		DB::StartTrans();
		$max = DB::GetOne('SELECT MAX(position) FROM '.$this->tab.'_field')+1;
		DB::Execute('INSERT INTO '.$this->tab.'_field(field, type, visible, required, param, position, filter)'.
					' VALUES(%s, %s, %d, %d, %s, %d, %d)',
					array($data['field'], $data['select_data_type'], $data['visible'], $data['required'], $param, $max, $data['filter']));
		DB::CompleteTrans();
		return true;
	} //submit_add_field
	public function dirty_read_changes($id, $time_from) {
		print('<b>'.$this->lang->t('The following changes were applied to this record while you were editing it.<br>Please revise this data and make sure to keep this record most accurate.').'</b><br>');
		$gb_cha = $this->init_module('Utils/GenericBrowser', null, $this->tab.'__changes');
		$table_columns_changes = array(	array('name'=>$this->lang->t('Date'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('Username'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('Field'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('Old value'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('New value'), 'width'=>1, 'wrapmode'=>'nowrap'));
		$gb_cha->set_table_columns( $table_columns_changes );

		$created = Utils_RecordBrowserCommon::get_record($this->tab, $id, true);
		$created['created_by_login'] = Base_UserCommon::get_user_login($created['created_by']);
		$field_hash = array();
		foreach($this->table_rows as $field => $args)
			$field_hash[$args['id']] = $field;
		$ret = DB::Execute('SELECT ul.login, c.id, c.edited_on, c.edited_by FROM '.$this->tab.'_edit_history AS c LEFT JOIN user_login AS ul ON ul.id=c.edited_by WHERE c.edited_on>=%T AND c.'.$this->tab.'_id=%d ORDER BY edited_on DESC',array($time_from,$id));
		while ($row = $ret->FetchRow()) {
			$changed = array();
			$ret2 = DB::Execute('SELECT * FROM '.$this->tab.'_edit_history_data WHERE edit_id=%d',array($row['id']));
			while($row2 = $ret2->FetchRow()) {
				if (isset($changed[$row2['field']])) {
					if (is_array($changed[$row2['field']]))
						array_unshift($changed[$row2['field']], $row2['old_value']);
					else
						$changed[$row2['field']] = array($row2['old_value'], $changed[$row2['field']]);
				} else {
					$changed[$row2['field']] = $row2['old_value'];
				}
				if (is_array($changed[$row2['field']]))
					sort($changed[$row2['field']]);
			}
			foreach($changed as $k=>$v) {
				$new = $this->get_val($field_hash[$k], $created, $created['id'], false, $this->table_rows[$field_hash[$k]]);
				$created[$k] = $v;
				$old = $this->get_val($field_hash[$k], $created, $created['id'], false, $this->table_rows[$field_hash[$k]]);
				$gb_row = $gb_cha->get_new_row();
//				eval_js('apply_changes_to_'.$k.'=function(){element = document.getElementsByName(\''.$k.'\')[0].value=\''.$v.'\';};');
//				$gb_row->add_action('href="javascript:apply_changes_to_'.$k.'()"', 'Apply', null, 'apply');
				$gb_row->add_data($row['edited_on'], Base_UserCommon::get_user_login($row['edited_by']), $field_hash[$k], $old, $new);
			}
		}
		$theme = $this->init_module('Base/Theme');
		$theme->assign('table',$this->get_html_of_module($gb_cha));
		$theme->assign('label',$this->lang->t('Recent Changes'));
		$theme->display('View_history');
	}
	public function view_edit_history($id){
		if ($this->is_back())
			return $this->back();
		$this->init();
		$gb_cur = $this->init_module('Utils/GenericBrowser', null, $this->tab.'__current');
		$gb_cha = $this->init_module('Utils/GenericBrowser', null, $this->tab.'__changes');
		$gb_ori = $this->init_module('Utils/GenericBrowser', null, $this->tab.'__original');

		$table_columns = array(	array('name'=>$this->lang->t('Field'), 'width'=>1, 'wrapmode'=>'nowrap'),
								array('name'=>$this->lang->t('Value'), 'width'=>1, 'wrapmode'=>'nowrap'));
		$table_columns_changes = array(	array('name'=>$this->lang->t('Date'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('Username'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('Field'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('Old value'), 'width'=>1, 'wrapmode'=>'nowrap'),
										array('name'=>$this->lang->t('New value'), 'width'=>1, 'wrapmode'=>'nowrap'));

		$gb_cur->set_table_columns( $table_columns );
		$gb_ori->set_table_columns( $table_columns );
		$gb_cha->set_table_columns( $table_columns_changes );

		$created = Utils_RecordBrowserCommon::get_record($this->tab, $id, true);
		$access = $this->get_access('fields', $created);
		$created['created_by_login'] = Base_UserCommon::get_user_login($created['created_by']);
		$field_hash = array();
		$edited = DB::GetRow('SELECT ul.login, c.edited_on FROM '.$this->tab.'_edit_history AS c LEFT JOIN user_login AS ul ON ul.id=c.edited_by WHERE c.'.$this->tab.'_id=%d ORDER BY edited_on DESC',array($id));
		if (!isset($edited['login']))
			return;
		$gb_cur->add_row($this->lang->t('Edited by'), $edited['login']);
		$gb_cur->add_row($this->lang->t('Edited on'), $edited['edited_on']);
		foreach($this->table_rows as $field => $args) {
			if ($access[$args['id']] == 'hide') continue;
			$field_hash[$args['id']] = $field;
			$val = $this->get_val($field, $created, $created['id'], false, $args);
			if ($created[$args['id']] !== '') $gb_cur->add_row($this->lang->t($field), $val);
		}

		$ret = DB::Execute('SELECT ul.login, c.id, c.edited_on, c.edited_by FROM '.$this->tab.'_edit_history AS c LEFT JOIN user_login AS ul ON ul.id=c.edited_by WHERE c.'.$this->tab.'_id=%d ORDER BY edited_on DESC',array($id));
		while ($row = $ret->FetchRow()) {
			$changed = array();
			$ret2 = DB::Execute('SELECT * FROM '.$this->tab.'_edit_history_data WHERE edit_id=%d',array($row['id']));
			while($row2 = $ret2->FetchRow()) {
				if ($access[$row2['field']] == 'hide') continue;
				$changed[$row2['field']] = $row2['old_value'];
				$last_row = $row2;
			}
			foreach($changed as $k=>$v) {
				if ($k=='') $gb_cha->add_row($row['edited_on'], Base_UserCommon::get_user_login($row['edited_by']), '', '', $last_row['old_value']);
				else {
					$new = $this->get_val($field_hash[$k], $created, $created['id'], false, $this->table_rows[$field_hash[$k]]);
					if ($this->table_rows[$field_hash[$k]]['type']=='multiselect') $v = Utils_RecordBrowserCommon::decode_multi($v);
					$created[$k] = $v;
					$old = $this->get_val($field_hash[$k], $created, $created['id'], false, $this->table_rows[$field_hash[$k]]);
					$gb_cha->add_row($row['edited_on'], Base_UserCommon::get_user_login($row['edited_by']), $this->lang->t($field_hash[$k]), $old, $new);
				}
			}
		}
		$gb_ori->add_row($this->lang->t('Created by'), $created['created_by_login']);
		$gb_ori->add_row($this->lang->t('Created on'), $created['created_on']);
		foreach($this->table_rows as $field => $args) {
			if ($access[$args['id']] == 'hide') continue;
			$val = $this->get_val($field, $created, $created['id'], false, $args);
			if ($created[$args['id']] !== '') $gb_ori->add_row($this->lang->t($field), $val);
		}
		$theme = $this->init_module('Base/Theme');
		$theme->assign('table',$this->get_html_of_module($gb_cur));
		$theme->assign('label',$this->lang->t('Current Record'));
		$theme->display('View_history');
		$theme = $this->init_module('Base/Theme');
		$theme->assign('table',$this->get_html_of_module($gb_cha));
		$theme->assign('label',$this->lang->t('Changes History'));
		$theme->display('View_history');
		$theme = $this->init_module('Base/Theme');
		$theme->assign('table',$this->get_html_of_module($gb_ori));
		$theme->assign('label',$this->lang->t('Original Record'));
		$theme->display('View_history');
		Base_ActionBarCommon::add('back','Back',$this->create_back_href());
		return true;
	}

	public function set_active($id, $state=true){
		DB::StartTrans();
		DB::Execute('UPDATE '.$this->tab.' SET active=%d WHERE id=%d',array($state?1:0,$id));
		DB::Execute('INSERT INTO '.$this->tab.'_edit_history(edited_on, edited_by, '.$this->tab.'_id) VALUES (%T,%d,%d)', array(date('Y-m-d G:i:s'), Acl::get_user(), $id));
		$edit_id = DB::Insert_ID($this->tab.'_edit_history','id');
		DB::Execute('INSERT INTO '.$this->tab.'_edit_history_data(edit_id, field, old_value) VALUES (%d,%s,%s)', array($edit_id, 'id', ($state?'REVERTED':'DELETED')));
		DB::CompleteTrans();
		return false;
	}
	public function restore_record($data, $id) {
		$this->init();
		$i = 3;
		$values = array();
		foreach($this->table_rows as $field => $args) {
			if ($field=='id') continue;
			$values[$args['id']] = $data[$i++]['DBvalue'];
		}
		$this->update_record($id,$values);
		return false;
	}
	public function set_defaults($arg){
		foreach ($arg as $k=>$v)
			$this->custom_defaults[$k] = $v;
	}
	public function set_filters_defaults($arg){
		if (!$this->isset_module_variable('def_filter')) $this->set_module_variable('def_filter', $arg);
	}
	public function set_default_order($arg){
		foreach ($arg as $k=>$v)
			$this->default_order[$k] = $v;
	}
	public function caption(){
		return $this->caption.': '.$this->action;
	}
	public function recordpicker($element, $format, $crits=array(), $cols=array(), $order=array(), $filters=array()) {
		if (!isset($this->lang)) $this->lang = $this->init_module('Base/Lang');
		$this->init();
		$this->set_module_variable('element',$element);
		$this->set_module_variable('format_func',$format);
		$theme = $this->init_module('Base/Theme');
		$theme->assign('filters', $this->show_filters($filters, $element));
		foreach	($crits as $k=>$v) {
			if (!is_array($v)) $v = array($v);
			if (isset($this->crits[$k]) && !empty($v)) {
				foreach ($v as $w) if (!in_array($w, $this->crits[$k])) $this->crits[$k][] = $w;
			} else $this->crits[$k] = $v;
		}
		$theme->assign('table', $this->show_data($this->crits, $cols, $order, false, true));
		load_js('modules/Utils/RecordBrowser/rpicker.js');

		$rpicker_ind = $this->get_module_variable('rpicker_ind');
		$init_func = 'init_all_rpicker_'.$element.' = function(id, cstring){';
		foreach($rpicker_ind as $v)
			$init_func .= 'rpicker_init(\''.$element.'\','.$v.',\''.Base_ThemeCommon::get_template_file('images/active_on.png').'\',\''.Base_ThemeCommon::get_template_file('images/active_off2.png').'\');';
		$init_func .= '}';
		eval_js($init_func.';init_all_rpicker_'.$element.'();');
		$theme->display('Record_picker');
	}
	public function admin() {
		$ret = DB::Execute('SELECT tab FROM recordbrowser_table_properties');
		$tb = $this->init_module('Utils/TabbedBrowser');
		while ($row=$ret->FetchRow()) {
			$tb->set_tab(ucfirst(str_replace('_',' ',$row['tab'])), array($this, 'record_management'), array($row['tab']));
		}
		$this->display_module($tb);
		$tb->tag();
	}
	public function record_management($table){
		$rb = $this->init_module('Utils/RecordBrowser',$table,$table);
		$this->display_module($rb, null, 'administrator_panel');
	}

	public function enable_quick_new_records() {
		$this->add_in_table = true;
	}
	public function set_custom_filter($arg, $spec){
		$this->custom_filters[$arg] = $spec;
	}
	public function set_crm_filter($field){
		$this->filter_field = $field;
	}
	public function cut_string($str, $len) {
		if ($len==-1) return $str;
		$ret = '';
		$strings = explode('<br>',$str);
		foreach ($strings as $str) {
			if ($ret) $ret .= '<br>';
			$oldc = $content = strip_tags($str);
			$content = str_replace('&nbsp;',' ',$content);
			if (strlen($content)>$len) {
				$label = substr($content, 0, $len).'...';
				$label = str_replace(' ','&nbsp;',$label);
				$label = str_replace($oldc, $label, $str);
				if (!strpos($str, 'Utils_Toltip__showTip(')) $label = '<span '.Utils_TooltipCommon::open_tag_attrs($content).'>'.$label.'</span>';
				else $label = preg_replace('/Utils_Toltip__showTip\(\'(.*?)\'/', 'Utils_Toltip__showTip(\''.escapeJS(htmlspecialchars($content)).'<hr>$1\'', $label);
				$ret .= $label;
			} else $ret .= $str;
		}
		return $ret;
	}

	public function mini_view($cols, $crits, $order, $info, $limit=null, $conf = array('actions_edit'=>true, 'actions_info'=>true)){
		$this->init();
		$gb = $this->init_module('Utils/GenericBrowser',$this->tab,$this->tab);
		$field_hash = array();
		foreach($this->table_rows as $field => $args)
			$field_hash[$args['id']] = $field;
		$header = array();
		$cut = array();
		$callbacks = array();
		foreach($cols as $k=>$v) {
			if (isset($v['cut'])) $cut[] = $v['cut'];
			else $cut[] = -1;
			if (isset($v['callback'])) $callbacks[] = $v['callback'];
			else $callbacks[] = null;
			if (is_array($v)) {
				$arr = array('name'=>$this->lang->t($field_hash[$v['field']]), 'width'=>$v['width']);
				$cols[$k] = $v['field'];
			} else {
				$arr = array('name'=>$this->lang->t($field_hash[$v]));
				$cols[$k] = $v;
			}
			$arr['wrapmode'] = 'nowrap';
			$header[] = $arr;
		}
		$gb->set_table_columns($header);

		$clean_order = array();
		foreach($order as $k=>$v) {
			$clean_order[] = array('column'=>$field_hash[$k],'order'=>$field_hash[$k],'direction'=>$v);
		}
		if ($limit!=null) {
			$limit = array('offset'=>0, 'numrows'=>$limit);
			$records_qty = Utils_RecordBrowserCommon::get_records_limit($this->tab, $crits);
			if ($records_qty>$limit['numrows']) print($this->lang->t('Displaying %s of %s records', array($limit['numrows'], $records_qty)));
		}
		$records = Utils_RecordBrowserCommon::get_records($this->tab, $crits, array(), $clean_order, $limit);
		$records = Utils_RecordBrowserCommon::format_long_text_array($this->tab,$records);
		foreach($records as $v) {
			$gb_row = $gb->get_new_row();
			$arr = array();
			foreach($cols as $k=>$w) {
				if (!isset($callbacks[$k])) $s = $this->get_val($field_hash[$w], $v, $v['id'], false, $this->table_rows[$field_hash[$w]]);
				else $s = call_user_func($callbacks[$k], $v);
				$arr[] = $this->cut_string($s, $cut[$k]);
			}
			$gb_row->add_data_array($arr);
			if (is_callable($info)) {
				$additional_info = call_user_func($info, $v).'<hr>';
			} else $additional_info = '';
			if (isset($conf['actions_info']) && $conf['actions_info']) $gb_row->add_info($additional_info.Utils_RecordBrowserCommon::get_html_record_info($this->tab, $v['id']));
			if (isset($conf['actions_view']) && $conf['actions_view']) $gb_row->add_action($this->create_callback_href(array($this,'navigate'),array('view_entry', 'view',$v['id'])),'View');
			if (isset($conf['actions_edit']) && $conf['actions_edit']) if ($this->get_access('edit',$v)) $gb_row->add_action($this->create_callback_href(array($this,'navigate'),array('view_entry', 'edit',$v['id'])),'Edit');
			if (isset($conf['actions_delete']) && $conf['actions_delete']) if ($this->get_access('delete',$v)) $gb_row->add_action($this->create_confirm_callback_href($this->lang->t('Are you sure you want to delete this record?'),array('Utils_RecordBrowserCommon','delete_record'),array($this->tab, $v['id'])),'Delete');
			if (isset($conf['actions_history']) && $conf['actions_history']) {
				$r_info = Utils_RecordBrowserCommon::get_record_info($this->tab, $v['id']);
				if ($r_info['edited_by']===null) $gb_row->add_action('','This record was never edited',null,'history_inactive');
				else $gb_row->add_action($this->create_callback_href(array($this,'navigate'),array('view_edit_history', $v['id'])),'View edit history',null,'history');
			}
		}
		$this->display_module($gb);
	}
}
?>
