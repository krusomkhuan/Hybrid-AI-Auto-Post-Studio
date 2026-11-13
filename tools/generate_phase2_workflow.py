"""Credential-free n8n Crypto V2 template; validate on the user's n8n version before activation."""
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
def node(name,kind,parameters,version,x,credentials=None):
    n={'id':name.lower().replace(' ','-'),'name':name,'type':'n8n-nodes-base.'+kind,'typeVersion':version,'position':[x,0],'parameters':parameters}
    if credentials:n['credentials']=credentials
    return n
prepare="""const timestamp = Math.floor(Date.now() / 1000).toString();
const nonce = $json.nonce;
const body = JSON.stringify({event_id: 'n8n_' + String($execution.id).slice(-20) + '_' + nonce.slice(0,32), operation:'tick'});
const url = 'https://STUDIO-URL-REPLACE/studio/integration.php';
return [{json:{timestamp,nonce,body,url}}];"""
nodes=[
node('Every 10 seconds','scheduleTrigger',{'rule':{'interval':[{'field':'seconds','secondsInterval':10}]}},1.2,0),
node('Nonce','crypto',{'action':'generate','encodingType':'hex','stringLength':32,'dataPropertyName':'nonce'},2,220),
node('Prepare Request','code',{'jsCode':prepare},2,440),
node('Body Hash','crypto',{'action':'hash','type':'SHA256','value':'={{ $json.body }}','encoding':'hex','dataPropertyName':'bodyHash'},2,660),
node('Sign Request','crypto',{'action':'hmac','type':'SHA256','value':"={{ 'POST\\n'.replace(/\\\\n/g, '\\n') + $json.url.replace(/^https?:\\/\\/[^/]+/, '') + '\\n'.replace(/\\\\n/g, '\\n') + $json.timestamp + '\\n'.replace(/\\\\n/g, '\\n') + $json.nonce + '\\n'.replace(/\\\\n/g, '\\n') + $json.bodyHash }}",'encoding':'hex','dataPropertyName':'signature'},2,880,{'crypto':{'id':'SELECT_YOUR_CRYPTO_CREDENTIAL','name':'Studio HMAC'}}),
node('Studio Publish Step','httpRequest',{'method':'POST','url':'={{ $json.url }}','sendHeaders':True,'headerParameters':{'parameters':[{'name':'X-Studio-Timestamp','value':'={{ $json.timestamp }}'},{'name':'X-Studio-Nonce','value':'={{ $json.nonce }}'},{'name':'X-Studio-Signature','value':'={{ $json.signature }}'},{'name':'Content-Type','value':'application/json'}]},'sendBody':True,'contentType':'raw','rawContentType':'application/json','body':'={{ $json.body }}','options':{'timeout':120000}},4.2,1100)
]
# Expressions are JavaScript; join with a real newline escape after n8n evaluates them.
nodes[4]['parameters']['value']="={{ ['POST', $json.url.replace(/^https?:\\/\\/[^/]+/, ''), $json.timestamp, $json.nonce, $json.bodyHash].join('\\n') }}"
connections={nodes[i]['name']:{'main':[[{'node':nodes[i+1]['name'],'type':'main','index':0}]]} for i in range(len(nodes)-1)}
workflow={'name':'Hybrid Studio · Phase 2 · Publishing Scheduler','active':False,'nodes':nodes,'connections':connections,'settings':{'executionOrder':'v1','timezone':'Asia/Bangkok','saveDataSuccessExecution':'none','saveDataErrorExecution':'none','saveManualExecutions':False},'pinData':{}}
folder=root/'studio-private/workflows';folder.mkdir(exist_ok=True)
(folder/'phase2-publishing.json').write_text(json.dumps(workflow,ensure_ascii=False,indent=2),encoding='utf-8')
print('Created inactive template: Crypto V2 credential required; no key embedded')
