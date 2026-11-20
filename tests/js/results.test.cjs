const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/js/hub-results.js','utf8');

function setup() {
    let callback;
    const requests=[];
    const status={textContent:''};
    const window={
        H5P:{externalDispatcher:{on:(event, listener)=>{callback=listener;}}},
        crypto:{randomUUID:()=> '5b3f7bad-efde-4a5b-a6a7-c1a7e64885f5'},
        document:{getElementById:()=>status},
        fetch:async (url, options)=>{requests.push({url,options});return {ok:true};}
    };
    vm.runInNewContext(source,{window});
    window.H5PHubResults.attach({endpoint:'/results',csrfToken:'csrf'});
    return {emit:statement=>callback({data:{statement},isFromChild:()=>true}),requests,status,window};
}

test('root H5P completion is saved even when isFromChild returns true',async()=>{
    const app=setup();
    app.emit({verb:{id:'http://adlnet.gov/expapi/verbs/completed'},result:{completion:true,score:{raw:1,max:1}}});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(app.requests.length,1);
    const request=app.requests[0];
    assert.equal(request.options.headers['X-CSRF-TOKEN'],'csrf');
    const payload=JSON.parse(request.options.body);
    assert.equal(payload.completed,true);
    assert.equal(payload.score,1);
    assert.equal(payload.max_score,1);
    assert.match(app.status.textContent,/Đã lưu/);
});

test('nested H5P statements and non-result events are ignored',()=>{
    const app=setup();
    app.emit({verb:{id:'http://adlnet.gov/expapi/verbs/completed'},context:{contextActivities:{parent:[{id:'root'}]}}});
    app.emit({verb:{id:'http://adlnet.gov/expapi/verbs/answered'},object:{definition:{extensions:{'http://h5p.org/x-api/h5p-subContentId':'child'}}}});
    app.emit({verb:{id:'http://adlnet.gov/expapi/verbs/initialized'}});
    assert.equal(app.requests.length,0);
});

test('failed saves are visible to the learner',async()=>{
    const app=setup();
    app.window.fetch=async()=>({ok:false});
    app.emit({verb:{id:'http://adlnet.gov/expapi/verbs/completed'},result:{completion:true}});
    await new Promise(resolve=>setImmediate(resolve));
    assert.match(app.status.textContent,/Chưa lưu/);
});
