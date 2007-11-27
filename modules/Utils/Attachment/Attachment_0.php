<?php
/**
 * Use this module if you want to add attachments to some page.
 * @author pbukowski@telaxus.com
 * @copyright pbukowski@telaxus.com
 * @license SPL
 * @version 0.1
 * @package utils-attachment
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

class Utils_Attachment extends Module {
	private $lang;
	private $key;
	private $persistant_deletion = false;
	private $group;
	private $view = true;
	private $view_deleted = true;
	private $edit = true;
	private $download = true;
	private $inline = false;

	public function construct($key,$group='') {
		if(!isset($key)) trigger_error('Key not given to attachment module',E_USER_ERROR);
		$this->lang = & $this->init_module('Base/Lang');
		$this->group = $group;
		$this->key = md5($key);
	}

	public function inline_attach_file($x=true) {
		$this->inline = $x;
	}

	public function set_persistant_delete($x=false) {
		$this->persistant_deletion = $x;
	}

	public function allow_edit($x=true) {
		$this->edit = $x;
	}

	public function allow_view($x=true) {
		$this->view = $x;
	}

	public function allow_view_deleted($x=true) {
		$this->view_deleted = $x;
	}

	public function allow_download($x=true) {
		$this->download = $x;
	}

	public function body() {
		if(!$this->view) {
			print($this->lang->t('You don\'t have permission to view attachments to this page'));
			return;
		}

		$vd = null;
		if($this->view_deleted && !$this->persistant_deletion) {
			$f = $this->init_module('Libs/QuickForm','view_deleted');
			$f->addElement('checkbox','view_del',$this->lang->t('View deleted attachments'),null,array('onClick'=>$f->get_submit_form_js()));
			$vd = & $this->get_module_variable('view_deleted');
			$f->setDefaults(array('view_del'=>$vd));
			if($f->validate()) {
				$vd = $f->exportValue('view_del');
			}
			$f->display();
		}

		$gb = $this->init_module('Utils/GenericBrowser',null,$this->key);
		$cols = array();
		if($vd)
			$cols[] = array('name'=>'Deleted','order'=>'ual.deleted','width'=>5);
		$cols[] = array('name'=>'Note', 'order'=>'uac.text','width'=>80);
		$cols[] = array('name'=>'Attachment', 'order'=>'ual.original','width'=>5);
		$gb->set_table_columns($cols);

		//tag for get.php
		$this->set_module_variable('download',$this->download);
		$this->set_module_variable('key',$this->key);
		$this->set_module_variable('group',$this->group);
		if($vd)
			$ret = $gb->query_order_limit('SELECT ual.deleted,ual.local,uac.revision as note_revision,uaf.revision as file_revision,ual.id,uac.created_on as note_on,(SELECT l.login FROM user_login l WHERE uac.created_by=l.id) as note_by,uac.text,uaf.original,uaf.created_on as upload_on,(SELECT l2.login FROM user_login l2 WHERE uaf.created_by=l2.id) as upload_by FROM utils_attachment_link ual INNER JOIN (utils_attachment_note uac,utils_attachment_file uaf) ON (uac.attach_id=ual.id AND uaf.attach_id=ual.id) WHERE ual.attachment_key=\''.$this->key.'\' AND ual.local='.DB::qstr($this->group).' AND uac.revision=(SELECT max(x.revision) FROM utils_attachment_note x WHERE x.attach_id=uac.attach_id) AND uaf.revision=(SELECT max(x.revision) FROM utils_attachment_file x WHERE x.attach_id=uaf.attach_id)','SELECT count(*) FROM utils_attachment_link ual WHERE ual.attachment_key=\''.$this->key.'\' AND ual.local='.DB::qstr($this->group));
		else
			$ret = $gb->query_order_limit('SELECT ual.local,uac.revision as note_revision,uaf.revision as file_revision,ual.id,uac.created_on as note_on,(SELECT l.login FROM user_login l WHERE uac.created_by=l.id) as note_by,uac.text,uaf.original,uaf.created_on as upload_on,(SELECT l2.login FROM user_login l2 WHERE uaf.created_by=l2.id) as upload_by FROM utils_attachment_link ual INNER JOIN (utils_attachment_note uac,utils_attachment_file uaf) ON (uac.attach_id=ual.id AND ual.id=uaf.attach_id) WHERE ual.attachment_key=\''.$this->key.'\' AND ual.local='.DB::qstr($this->group).' AND uac.revision=(SELECT max(x.revision) FROM utils_attachment_note x WHERE x.attach_id=uac.attach_id) AND uaf.revision=(SELECT max(x.revision) FROM utils_attachment_file x WHERE x.attach_id=uaf.attach_id) AND ual.deleted=0','SELECT count(*) FROM utils_attachment_link ual WHERE ual.attachment_key=\''.$this->key.'\' AND ual.local='.DB::qstr($this->group).' AND ual.deleted=0');
		while($row = $ret->FetchRow()) {
			$r = $gb->get_new_row();

			$file = '<a href="modules/Utils/Attachment/get.php?'.http_build_query(array('id'=>$row['id'],'revision'=>$row['file_revision'],'path'=>$this->get_path(),'cid'=>CID)).'">'.$row['original'].'</a>';
			$info = $this->lang->t('Last note by %s<br>Last note on %s<br>Number of note edits: %d<br>Last file uploaded by %s<br>Last file uploaded on %s<br>Number of file uploads: %d',array($row['note_by'],Base_RegionalSettingsCommon::time2reg($row['note_on']),$row['note_revision'],$row['upload_by'],Base_RegionalSettingsCommon::time2reg($row['upload_on']),$row['file_revision']));
			$r->add_info($info);
			if($this->edit) {
				$r->add_action($this->create_callback_href(array($this,'edit_note'),array($row['id'],$row['text'])),'edit');
				$r->add_action($this->create_confirm_callback_href($this->lang->ht('Delete this entry?'),array($this,'delete'),$row['id']),'delete');
				$r->add_action($this->create_callback_href(array($this,'edition_history'),$row['id']),'history');
			}
			if($vd)
				$r->add_data(($row['deleted']?'yes':'no'),$row['text'],$file);
			else
				$r->add_data($row['text'],$file);
		}
		if($this->inline) {
			print('<a '.$this->create_callback_href(array($this,'attach_file')).'>'.$this->lang->t('Attach note').'</a>');
		} else {
			Base_ActionBarCommon::add('folder','Attach note',$this->create_callback_href(array($this,'attach_file')));
		}

		$this->display_module($gb);
	}

	public function edition_history($id) {
		if($this->is_back()) return false;

		if($this->inline)
			print('<a '.$this->create_back_href().'>'.$this->lang->t('back').'</a>');
		else
			Base_ActionBarCommon::add('back','Back',$this->create_back_href());


		$gb = $this->init_module('Utils/GenericBrowser',null,'hn'.$this->key);
		$gb->set_table_columns(array(
				array('name'=>'Revision', 'order'=>'uac.revision','width'=>5),
				array('name'=>'Date', 'order'=>'note_on','width'=>15),
				array('name'=>'Who', 'order'=>'note_by','width'=>15),
				array('name'=>'Note', 'order'=>'uac.text')
			));

		$ret = $gb->query_order_limit('SELECT uac.revision,uac.created_on as note_on,(SELECT l.login FROM user_login l WHERE uac.created_by=l.id) as note_by,uac.text FROM utils_attachment_note uac WHERE uac.attach_id='.$id, 'SELECT count(*) FROM utils_attachment_note uac WHERE uac.attach_id='.$id);
		while($row = $ret->FetchRow()) {
			$r = $gb->get_new_row();
			$r->add_data($row['revision'],$row['note_on'],$row['note_by'],$row['text']);
		}
		$this->display_module($gb);

		$gb = $this->init_module('Utils/GenericBrowser',null,'ha'.$this->key);
		$gb->set_table_columns(array(
				array('name'=>'Revision', 'order'=>'uaf.revision','width'=>5),
				array('name'=>'Date', 'order'=>'upload_on','width'=>15),
				array('name'=>'Who', 'order'=>'upload_by','width'=>15),
				array('name'=>'Attachment', 'order'=>'uaf.original')
			));

		$ret = $gb->query_order_limit('SELECT uaf.revision,uaf.created_on as upload_on,(SELECT l.login FROM user_login l WHERE uaf.created_by=l.id) as upload_by,uaf.original FROM utils_attachment_file uaf WHERE uaf.attach_id='.$id, 'SELECT count(*) FROM utils_attachment_file uaf WHERE uaf.attach_id='.$id);
		while($row = $ret->FetchRow()) {
			$r = $gb->get_new_row();
			$file = '<a href="modules/Utils/Attachment/get.php?'.http_build_query(array('id'=>$id,'revision'=>$row['revision'],'path'=>$this->get_path(),'cid'=>CID)).'">'.$row['original'].'</a>';
			$r->add_data($row['revision'],$row['upload_on'],$row['upload_by'],$file);
		}
		$this->display_module($gb);

		return true;
	}

	public function attach_file() {
		$form = & $this->init_module('Utils/FileUpload',array(false));
		$form->addElement('header', 'upload', $this->lang->t('Attach note'));
		$fck = $form->addElement('fckeditor', 'note', $this->lang->t('Note'));
		$fck->setFCKProps('800','300');
		$form->set_upload_button_caption('Save');
		if($form->getSubmitValue('note')=='' && $form->getSubmitValue('uploaded_file')=='')
			$form->addRule('note',$this->lang->t('Please enter note or choose file'),'required');
		$this->ret_attach = true;
		$this->display_module($form, array( array($this,'submit_attach') ));
		return $this->ret_attach;
	}

	public function submit_attach($file,$oryg,$data) {
		DB::Execute('INSERT INTO utils_attachment_link(attachment_key,local) VALUES(%s,%s)',array($this->key,$this->group));
		$id = DB::Insert_ID('utils_attachment_link','id');
		DB::Execute('INSERT INTO utils_attachment_file(attach_id,original,created_by,revision) VALUES(%d,%s,%d,0)',array($id,$oryg,Base_UserCommon::get_my_user_id()));
		DB::Execute('INSERT INTO utils_attachment_note(attach_id,text,created_by,revision) VALUES(%d,%s,%d,0)',array($id,$data['note'],Base_UserCommon::get_my_user_id()));
		if($file) {
			$local = $this->get_data_dir().$this->group;
			@mkdir($local,0777,true);
			rename($file,$local.'/'.$id.'_0');
		}
		$this->ret_attach = false;
	}

	public function edit_note($id,$text) {
		$form = & $this->init_module('Utils/FileUpload',array(false));
		$form->addElement('header', 'upload', $this->lang->t('Edit note'));
		$fck = $form->addElement('fckeditor', 'note', $this->lang->t('Note'));
		$form->setDefaults(array('note'=>$text));
		$fck->setFCKProps('800','300');
		$form->set_upload_button_caption('Save');
		if($form->getSubmitValue('note')=='' && $form->getSubmitValue('uploaded_file')=='')
			$form->addRule('note',$this->lang->t('Please enter note or choose file'),'required');
		$this->ret_attach = true;
		$form->addElement('header',null,$this->lang->t('Replace attachment with file'));
		$this->display_module($form, array( array($this,'submit_edit'),$id,$text));
		return $this->ret_attach;
	}

	public function submit_edit($file,$oryg,$data,$id,$text) {
		if($data['note']!=$text) {
			DB::StartTrans();
			$rev = DB::GetOne('SELECT max(x.revision) FROM utils_attachment_note x WHERE x.attach_id=%d',array($id));
			DB::Execute('INSERT INTO utils_attachment_note(text,attach_id,revision,created_by) VALUES (%s,%d,%d,%d)',array($data['note'],$id,$rev+1,Base_UserCommon::get_my_user_id()));
			DB::CompleteTrans();
		}
		if($file) {
			DB::StartTrans();
			$rev = DB::GetOne('SELECT max(x.revision) FROM utils_attachment_file x WHERE x.attach_id=%d',array($id));
			$rev = $rev+1;
			DB::Execute('INSERT INTO utils_attachment_file(attach_id,original,created_by,revision) VALUES(%d,%s,%d,%d)',array($id,$oryg,Base_UserCommon::get_my_user_id(),$rev));
			DB::CompleteTrans();
			$local = $this->get_data_dir().$this->group;
			@mkdir($local,0777,true);
			rename($file,$local.'/'.$id.'_'.$rev);
		}
		$this->ret_attach = false;
	}

	public function delete($id) {
		if($this->persistant_deletion) {
			DB::Execute('DELETE FROM utils_attachment_note WHERE attach_id=%d',array($id));
			DB::Execute('DELETE FROM utils_attachment_file WHERE attach_id=%d',array($id));
			DB::Execute('DELETE FROM utils_attachment_link WHERE id=%d',array($id));
			unlink($this->get_data_dir().$id);
		} else {
			DB::Execute('UPDATE utils_attachment_link SET deleted=1 WHERE id=%d',array($id));
		}
	}
}

?>
