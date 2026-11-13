from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=root/'studio-private/views/content.php';s=p.read_text(encoding='utf-8')
marker='<div class="editor-grid">'
summary='''<?php $publication=Studio\\publish_summary($c['targets']); ?><div class="notice mb-4"><strong><?= e($publication['status']) ?></strong> · อัตโนมัติสำเร็จ <?= e($publication['automatic']) ?>/2 · Facebook ส่วนตัว <?= $publication['manual']?'ผู้ดูแลยืนยันแล้ว':'รอดำเนินการ' ?></div>
'''
assert marker in s;s=s.replace(marker,summary+marker,1);p.write_text(s,encoding='utf-8')
p=root/'studio-private/views/queue.php';s=p.read_text(encoding='utf-8')
marker='<span class="small text-danger">ต้องตรวจผลบนแพลตฟอร์มก่อน ห้ามสร้างโพสต์ใหม่ซ้ำ</span>'
addition='''<?php if($j['remote_post_id']): ?><form method="post" action="<?= e(url('queue')) ?>" class="mb-2"><?= Security::field() ?><input type="hidden" name="action" value="reconcile_job"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>"><button class="btn btn-sm btn-outline-primary" type="submit">ตรวจสถานะ Remote ID เดิม</button></form><?php endif; ?>'''
assert marker in s;s=s.replace(marker,addition+marker);p.write_text(s,encoding='utf-8')
print('Publication summary and safe reconciliation UI added')
